<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

use GuzzleHttp\Client;
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokApiException;
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokTokenExpiredException;
use Goletter\Adv\Support\RotatesAccessTokens;
use Goletter\Adv\Support\SafeLogs;
use GuzzleHttp\Exception\RequestException;

class TikTokClient
{
    use RotatesAccessTokens;
    use SafeLogs;

    protected Client $http;
    protected string $baseUri;

    protected array $defaultHeaders = [];

    /**
     * 接口调用日志回调。
     *
     * @var null|callable(array $log): void
     */
    protected static $callLogHandler = null;

    /**
     * @param string|list<string> $accessToken 单个或多个 token；失败时按顺序轮询下一个
     */
    public function __construct(
        string|array $accessToken,
        string $baseUri = 'https://business-api.tiktok.com'
    ) {
        $this->bootstrapAccessTokens($accessToken);
        $this->baseUri = rtrim($baseUri, '/');

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

    public function delete(string $uri, array $query = [], string $api_interface = ''): array
    {
        return $this->request('DELETE', $uri, $query, [], $api_interface);
    }

    /**
     * TikTok page / page_size / page_info 分页
     *
     * @param callable(int $page, int $pageSize): array $fetcher 返回完整 API 响应
     * @return \Generator<int, array>
     */
    public function paginate(callable $fetcher, int $pageSize = 50, int $max = 100000): \Generator
    {
        $page = 1;
        $count = 0;

        do {
            $response = $fetcher($page, $pageSize);
            $list = $response['data']['list'] ?? [];

            foreach ($list as $item) {
                yield $item;

                if (++$count >= $max) {
                    return;
                }
            }

            $pageInfo = $response['data']['page_info'] ?? [];
            $totalPage = (int) ($pageInfo['total_page'] ?? $page);
            $page++;
        } while ($page <= $totalPage);
    }

    /**
     * 一次性拉全（不推荐大数据量）
     *
     * @param callable(int $page, int $pageSize): array $fetcher
     */
    public function getAll(callable $fetcher, int $pageSize = 50, int $max = 100000): array
    {
        return iterator_to_array($this->paginate($fetcher, $pageSize, $max));
    }

    protected function request(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        string $api_interface = '',
    ): array {
        return $this->withTokenFailover(function () use ($method, $uri, $query, $body, $api_interface): array {
            $startedAt = microtime(true);

            try {
                $options = [
                    'query' => $query,
                    'headers' => array_merge(
                        [
                            'Access-Token' => $this->accessToken,
                            'Content-Type' => 'application/json',
                            'Accept-Encoding' => 'identity',
                        ],
                        $this->defaultHeaders
                    ),
                ];

                if ($body !== []) {
                    $options['json'] = $body;
                }

                if (! str_starts_with($uri, '/')) {
                    $uri = '/' . $uri;
                }

                $response = $this->http->request($method, $uri, $options);
                $data = json_decode((string) $response->getBody(), true);

                $this->handleErrorIfNeeded($data);
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
            } catch (TikTokApiException $e) {
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
                    throw new TikTokApiException($e->getMessage(), (int) $e->getCode(), [], $e);
                }

                $decoded = json_decode((string) $response->getBody(), true) ?: [];
                $payload = [...$decoded, 'token' => $this->accessToken];
                $message = (string) ($decoded['message'] ?? $e->getMessage());
                $this->pushCallLog(
                    $method,
                    $uri,
                    $api_interface,
                    false,
                    $message,
                    $this->accessToken,
                    $durationMs
                );

                throw new TikTokApiException(
                    json_encode($payload, JSON_UNESCAPED_UNICODE) ?: $e->getMessage(),
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

        $interface = $apiInterface !== ''
            ? $apiInterface
            : (strtoupper($method) . ':' . (preg_replace('#^/open_api/v\d+(?:\.\d+)?#', '', $uri) ?: $uri));

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
            'api_interface' => $interface,
            'is_success' => $success ? 1 : 0,
            'error' => $error,
            'duration_ms' => $durationMs,
            'call_hour' => date('Y-m-d H:00:00'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function handleErrorIfNeeded(?array $data): void
    {
        if ($data === null) {
            throw new TikTokApiException('TikTok API 返回空响应或非 JSON 内容', 0, []);
        }

        $code = $data['code'] ?? null;
        if ($code === null || (int) $code === 0) {
            return;
        }

        $message = (string) ($data['message'] ?? 'TikTok API error');

        if (in_array((int) $code, [40100, 40101, 40102], true)) {
            throw new TikTokTokenExpiredException($message, (int) $code, $data);
        }

        throw new TikTokApiException($message, (int) $code, $data);
    }
}
