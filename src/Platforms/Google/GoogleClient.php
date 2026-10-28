<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

use GuzzleHttp\Client;
use Goletter\Adv\Platforms\Google\Exceptions\GoogleApiException;
use Goletter\Adv\Platforms\Google\Exceptions\GoogleTokenExpiredException;
use Goletter\Adv\Support\RotatesAccessTokens;
use Goletter\Adv\Support\SafeLogs;
use GuzzleHttp\Exception\RequestException;

/**
 * Google Ads API REST 客户端
 *
 * @see https://developers.google.com/google-ads/api/rest/overview
 */
class GoogleClient
{
    use RotatesAccessTokens;
    use SafeLogs;

    protected Client $http;

    protected array $defaultHeaders = [];

    protected string $developerToken;

    protected string $loginCustomerId;

    protected string $apiVersion;

    /**
     * 接口调用日志回调。
     *
     * @var null|callable(array $log): void
     */
    protected static $callLogHandler = null;

    /**
     * @param string|list<string> $accessToken 单个或多个 OAuth token；失败时按顺序轮询下一个
     */
    public function __construct(
        string|array $accessToken,
        string $developerToken,
        string $loginCustomerId = '',
        string $apiVersion = 'v19',
        string $baseUri = 'https://googleads.googleapis.com'
    ) {
        $this->bootstrapAccessTokens($accessToken);
        $this->developerToken = $developerToken;
        $this->loginCustomerId = $loginCustomerId;
        $this->apiVersion = $apiVersion;
        $this->http = new Client([
            'base_uri' => rtrim($baseUri, '/') . '/',
            'timeout' => 120,
        ]);
    }

    public function setDefaultHeaders(array $headers): self
    {
        $this->defaultHeaders = $headers;

        return $this;
    }

    public function getDeveloperToken(): string
    {
        return $this->developerToken;
    }

    public function getLoginCustomerId(): string
    {
        return $this->loginCustomerId;
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * 客户 ID 去掉连字符，如 123-456-7890 -> 1234567890
     */
    public static function normalizeCustomerId(string $customerId): string
    {
        if (str_starts_with($customerId, 'customers/')) {
            $customerId = substr($customerId, strlen('customers/'));
        }

        return str_replace('-', '', $customerId);
    }

    /**
     * @param null|callable(array $log): void $handler
     */
    public static function setCallLogHandler(?callable $handler): void
    {
        self::$callLogHandler = $handler;
    }

    public function get(string $uri, array $query = [], string $api_interface = ''): array
    {
        return $this->request('GET', $uri, $query, [], $api_interface);
    }

    public function post(string $uri, array $body = [], array $query = [], string $api_interface = ''): array
    {
        return $this->request('POST', $uri, $query, $body, $api_interface);
    }

    public function patch(string $uri, array $body = [], array $query = [], string $api_interface = ''): array
    {
        return $this->request('PATCH', $uri, $query, $body, $api_interface);
    }

    /**
     * GAQL 查询（单页）
     */
    public function search(
        string $customerId,
        string $query,
        ?string $pageToken = null,
        int $pageSize = 10000,
        string $api_interface = '',
    ): array {
        $customerId = self::normalizeCustomerId($customerId);
        $body = [
            'query' => $query,
            'pageSize' => $pageSize,
        ];
        if ($pageToken !== null && $pageToken !== '') {
            $body['pageToken'] = $pageToken;
        }

        return $this->post(
            "/{$this->apiVersion}/customers/{$customerId}/googleAds:search",
            $body,
            [],
            $api_interface !== '' ? $api_interface : 'POST:/customers/{customer_id}/googleAds:search',
        );
    }

    /**
     * GAQL 流式分页
     *
     * @return \Generator<int, array>
     */
    public function iterateSearch(
        string $customerId,
        string $query,
        int $pageSize = 10000,
        int $maxRows = 1000000,
        string $api_interface = '',
    ): \Generator {
        $pageToken = null;
        $count = 0;

        do {
            $response = $this->search($customerId, $query, $pageToken, $pageSize, $api_interface);
            foreach ($response['results'] ?? [] as $row) {
                yield $row;
                if (++$count >= $maxRows) {
                    return;
                }
            }
            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    protected function request(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        string $api_interface = '',
    ): array {
        if ($this->developerToken === '') {
            throw new GoogleApiException('Google Ads API 需要配置 developer-token', 0, []);
        }

        return $this->withTokenFailover(function () use ($method, $uri, $query, $body, $api_interface): array {
            $startedAt = microtime(true);

            try {
                $headers = array_merge([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'developer-token' => $this->developerToken,
                    'Content-Type' => 'application/json',
                ], $this->defaultHeaders);

                if ($this->loginCustomerId !== '') {
                    $headers['login-customer-id'] = self::normalizeCustomerId($this->loginCustomerId);
                }

                $options = [
                    'headers' => $headers,
                ];

                if ($query !== []) {
                    $options['query'] = $query;
                }

                if ($body !== []) {
                    $options['json'] = $body;
                }

                if (! str_starts_with($uri, '/')) {
                    $uri = '/' . $uri;
                }

                $response = $this->http->request($method, $uri, $options);
                $status = $response->getStatusCode();
                $raw = (string) $response->getBody();
                $data = $raw === '' ? [] : (json_decode($raw, true) ?? []);

                if ($status >= 400) {
                    $this->handleHttpError($status, $data);
                }

                if (isset($data['error'])) {
                    $this->handleApiError($data['error'], $data);
                }

                $this->pushCallLog(
                    $method,
                    $uri,
                    $api_interface,
                    true,
                    '',
                    $this->accessToken,
                    $this->elapsedMs($startedAt)
                );

                return $data;
            } catch (GoogleApiException $e) {
                $this->pushCallLog(
                    $method,
                    $uri,
                    $api_interface,
                    false,
                    $e->getMessage(),
                    $this->accessToken,
                    $this->elapsedMs($startedAt)
                );
                throw $e;
            } catch (RequestException $e) {
                $durationMs = $this->elapsedMs($startedAt);
                $response = $e->getResponse();
                if ($response === null) {
                    $this->pushCallLog(
                        $method,
                        $uri,
                        $api_interface,
                        false,
                        $e->getMessage(),
                        $this->accessToken,
                        $durationMs
                    );
                    throw new GoogleApiException($e->getMessage(), (int) $e->getCode(), [], $e);
                }

                $bodyContents = (string) $response->getBody();
                $decoded = json_decode($bodyContents, true) ?: [];
                $message = (string) ($decoded['error']['message'] ?? ($bodyContents !== '' ? $bodyContents : $e->getMessage()));
                $this->pushCallLog(
                    $method,
                    $uri,
                    $api_interface,
                    false,
                    $message,
                    $this->accessToken,
                    $durationMs
                );

                throw new GoogleApiException(
                    $bodyContents !== '' ? $bodyContents : $e->getMessage(),
                    (int) $e->getCode(),
                    $decoded,
                    $e
                );
            }
        });
    }

    protected function elapsedMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    protected function pushCallLog(
        string $method,
        string $uri,
        string $apiInterface,
        bool $success,
        string $error = '',
        string $token = '',
        int $durationMs = 0,
    ): void {
        if (! is_callable(self::$callLogHandler)) {
            return;
        }

        if ($apiInterface === '') {
            $path = preg_replace('#^/v\d+#', '', $uri) ?: $uri;
            $path = preg_replace('#(?<=/customers/)[^/:]+#', '{customer_id}', $path) ?: $path;
            $apiInterface = strtoupper($method) . ':' . $path;
        }

        if ($error || ! $success) {
            $this->safeLog(
                [
                    'message' => $error,
                    'url' => $uri,
                    'success' => $success,
                    'token' => $token,
                    'duration_ms' => $durationMs,
                ],
                '接口日志',
                'call_logs'
            );
        }

        (self::$callLogHandler)([
            'token' => $token,
            'api_interface' => $apiInterface,
            'is_success' => $success ? 1 : 0,
            'error' => $error,
            'duration_ms' => $durationMs,
            'call_hour' => date('Y-m-d H:00:00'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function handleHttpError(int $status, array $data): void
    {
        if (isset($data['error']) && is_array($data['error'])) {
            $this->handleApiError($data['error'], $data);
        }

        $err = $data['error'] ?? [];
        $errMsg = $err['message'] ?? null;
        $message = is_array($errMsg)
            ? (json_encode($errMsg, JSON_UNESCAPED_UNICODE) ?: "HTTP {$status}")
            : (string) ($errMsg ?? "HTTP {$status}");

        if ($status === 401) {
            throw new GoogleTokenExpiredException($message, $status, $data);
        }

        throw new GoogleApiException($message, $status, $data);
    }

    protected function handleApiError(array $error, array $raw): void
    {
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? 'Google Ads API error');
        $status = (string) ($error['status'] ?? '');

        if ($code === 401 || in_array($status, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true)) {
            if ($status === 'PERMISSION_DENIED' && ! $this->isAuthRelated($error)) {
                throw new GoogleApiException($message, $code, $raw);
            }
            throw new GoogleTokenExpiredException($message, $code, $raw);
        }

        throw new GoogleApiException($message, $code, $raw);
    }

    protected function isAuthRelated(array $error): bool
    {
        $details = $error['details'] ?? [];
        foreach ($details as $detail) {
            $errors = $detail['errors'] ?? [];
            foreach ($errors as $item) {
                $authError = $item['errorCode']['authenticationError'] ?? null;
                if ($authError !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
