<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use GuzzleHttp\Client;
use Goletter\Adv\Platforms\Facebook\Exceptions\FacebookApiException;
use Goletter\Adv\Platforms\Facebook\Exceptions\FacebookTokenExpiredException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class FacebookClient
{
    protected Client $http;
    protected string $accessToken;

    /** @deprecated 拼写保留以兼容旧调用，语义为 businessId */
    protected int $busineId;

    protected int $platformId;
    protected string $baseUri;
    protected string $apiVersion;

    protected array $defaultHeaders = [
        'Accept-Encoding' => 'identity',
    ];

    /**
     * FB 响应头 x-app-usage 的全局回调。
     *
     * @var null|callable(array $usage, string $accessToken, int $busineId, int $platformId): void
     */
    protected static $appUsageHandler = null;

    /**
     * FB 接口调用日志回调。
     *
     * @var null|callable(array $log): void
     */
    protected static $callLogHandler = null;

    public function __construct(
        string $accessToken,
        int $busineId = 0,
        int $platformId = 0,
        string $apiVersion = 'v24.0'
    ) {
        $this->accessToken = $accessToken;
        $this->busineId = $busineId;
        $this->platformId = $platformId;
        $this->apiVersion = $apiVersion;
        $this->baseUri = "https://graph.facebook.com/{$apiVersion}";

        $this->http = new Client([
            'base_uri' => $this->baseUri,
            'timeout' => 60,
        ]);
    }

    public function setDefaultHeaders(array $headers): self
    {
        $this->defaultHeaders = $headers;

        return $this;
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function get(string $uri, array $query = [], string $api_interface = ''): array
    {
        return $this->request('GET', $uri, $query, [], $api_interface);
    }

    public function post(string $uri, array $body = [], array $query = [], string $api_interface = ''): array
    {
        return $this->request('POST', $uri, $query, $body, $api_interface);
    }

    public function delete(string $uri, array $query = [], string $api_interface = ''): array
    {
        return $this->request('DELETE', $uri, $query, [], $api_interface);
    }

    /**
     * @param null|callable(array $usage, string $accessToken, int $busineId, int $platformId): void $handler
     */
    public static function setAppUsageHandler(?callable $handler): void
    {
        self::$appUsageHandler = $handler;
    }

    /**
     * @param null|callable(array $log): void $handler
     */
    public static function setCallLogHandler(?callable $handler): void
    {
        self::$callLogHandler = $handler;
    }

    protected function request(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        string $api_interface = ''
    ): array {
        try {
            $options = [
                'query' => $query,
            ];
            // OAuth 换 token 等场景可不传 user access_token
            if ($this->accessToken !== '') {
                $options['query']['access_token'] = $this->accessToken;
            }

            if ($this->defaultHeaders !== []) {
                $options['headers'] = $this->defaultHeaders;
            }

            if ($body !== []) {
                $options['json'] = $body;
            }

            $uri = $this->normalizeUri($uri);

            $response = $this->http->request($method, $uri, $options);
            $this->captureAppUsage($response, $this->accessToken, $this->busineId, $this->platformId);
            $data = json_decode((string) $response->getBody(), true) ?: [];

            if (isset($data['error'])) {
                $this->pushCallLog(
                    $method,
                    $uri,
                    $api_interface,
                    false,
                    (string) ($data['error']['message'] ?? ''),
                    $this->accessToken
                );
                $this->handleError($data['error'], $data);
            }

            $this->pushCallLog($method, $uri, $api_interface, true, '', $this->accessToken);

            return $data;
        } catch (FacebookApiException $e) {
            throw $e;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response && str_contains($e->getMessage(), 'Calls to this api have exceeded the rate limit')) {
                $this->safeLog(
                    ['token' => $this->accessToken, 'headers' => $response->getHeaders()],
                    'limit-exceeded',
                    'limit'
                );
            }

            if ($response) {
                $this->captureAppUsage($response, $this->accessToken, $this->busineId, $this->platformId);
                $nowBody = json_decode((string) $response->getBody(), true) ?: [];
                $payload = [...$nowBody, 'token' => $this->accessToken];
            } else {
                $nowBody = [];
                $payload = ['message' => $e->getMessage(), 'token' => $this->accessToken];
            }

            $this->pushCallLog(
                $method,
                $uri,
                $api_interface,
                false,
                (string) ($nowBody['error']['message'] ?? $e->getMessage()),
                $this->accessToken
            );

            throw new FacebookApiException(
                json_encode($payload, JSON_UNESCAPED_UNICODE) ?: $e->getMessage(),
                (int) $e->getCode(),
                $nowBody,
                $e
            );
        }
    }

    protected function captureAppUsage(
        ResponseInterface $response,
        string $accessToken,
        int $busineId,
        int $platformId
    ): void {
        $header = $response->getHeaderLine('x-app-usage');
        if ($header === '' || ! is_callable(self::$appUsageHandler)) {
            return;
        }
        $usage = json_decode($header, true);
        if (is_array($usage)) {
            (self::$appUsageHandler)($usage, $accessToken, $busineId, $platformId);
        }
    }

    protected function pushCallLog(
        string $method,
        string $uri,
        string $apiInterface,
        bool $success,
        string $error = '',
        string $token = '',
    ): void {
        if (! is_callable(self::$callLogHandler)) {
            return;
        }
        $interface = $apiInterface !== ''
            ? $apiInterface
            : (strtoupper($method) . ':' . preg_replace('#^/v\d+\.\d+#', '', $uri));

        if ($error || ! $success) {
            $this->safeLog(
                ['message' => $error, 'url' => $uri, 'success' => $success, 'token' => $token],
                '接口日志',
                'call_logs'
            );
        }

        (self::$callLogHandler)([
            'token' => $token,
            'api_interface' => $interface,
            'is_success' => $success ? 1 : 0,
            'error' => $error,
            'call_hour' => date('Y-m-d H:00:00'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 规范化请求路径
     */
    protected function normalizeUri(string $uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $parsed = parse_url($uri);

            return $parsed['path'] ?? '/';
        }

        if (preg_match('#^/v\d+\.\d+/#', $uri)) {
            return $uri;
        }

        if (! str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }
        if (! str_starts_with($uri, '/' . $this->apiVersion . '/')) {
            $uri = '/' . $this->apiVersion . $uri;
        }

        return $uri;
    }

    /**
     * @return array{0: string, 1: array}
     */
    protected function parsePagingNextUrl(string $nextUrl): array
    {
        $parsed = parse_url($nextUrl);
        $path = $parsed['path'] ?? '/';
        $query = [];
        if (! empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        unset($query['access_token']);

        return [$path, $query];
    }

    protected function handleError(array $error, array $raw): void
    {
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? 'Facebook API error');

        if ($code === 190) {
            throw new FacebookTokenExpiredException($message, $code, $raw);
        }

        throw new FacebookApiException($message, $code, $raw);
    }

    /**
     * Facebook Cursor / next URL 分页
     *
     * @return \Generator<int, array>
     */
    public function paginate(
        string $uri,
        array $query = [],
        string $api_interface = '',
        int $max = 100000
    ): \Generator {
        $next = $uri;
        $params = $query;
        $count = 0;

        while ($next) {
            if (str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
                [$next, $params] = $this->parsePagingNextUrl($next);
            }
            $response = $this->get($next, $params, $api_interface);

            if (! isset($response['data'])) {
                break;
            }

            foreach ($response['data'] as $item) {
                yield $item;

                if (++$count >= $max) {
                    return;
                }
            }

            $next = $response['paging']['next'] ?? null;
            $params = [];
        }
    }

    public function getAll(string $uri, array $query = [], string $api_interface = ''): array
    {
        return iterator_to_array(
            $this->paginate($uri, $query, $api_interface)
        );
    }

    protected function safeLog(mixed $data, string $message = '', string $channel = ''): void
    {
        if (function_exists('logging')) {
            logging($data, $message, $channel);
        }
    }
}
