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
    /** Graph Batch 单次最大子请求数 */
    public const BATCH_MAX_SIZE = 50;

    /** 常见限流相关错误码：4 App、17 User、32 Page、613 自定义限流 */
    protected const RATE_LIMIT_CODES = [4, 17, 32, 613];

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

    /** 限流重试最大次数（不含首次） */
    protected int $maxRetries = 5;

    /** 指数退避基数（毫秒） */
    protected int $retryBaseDelayMs = 200;

    /** 两次请求之间的最小间隔（毫秒），0 表示不强制间隔 */
    protected int $minRequestIntervalMs = 0;

    /** x-app-usage 任一指标达到该阈值时主动减速 */
    protected int $usageThrottleThreshold = 80;

    /** 用量超阈值时额外等待（毫秒） */
    protected int $usageThrottleDelayMs = 1000;

    /** @var float 上次实际发出请求的时间戳（微秒） */
    protected float $lastRequestAt = 0.0;

    /** @var array{call_count?: int, total_cputime?: int, total_time?: int}|null */
    protected ?array $lastAppUsage = null;

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

    /**
     * 配置频率限制相关行为。
     *
     * @param array{
     *     max_retries?: int,
     *     retry_base_delay_ms?: int,
     *     min_interval_ms?: int,
     *     usage_threshold?: int,
     *     usage_delay_ms?: int
     * } $options
     */
    public function configureRateLimit(array $options): self
    {
        if (isset($options['max_retries'])) {
            $this->maxRetries = max(0, (int) $options['max_retries']);
        }
        if (isset($options['retry_base_delay_ms'])) {
            $this->retryBaseDelayMs = max(0, (int) $options['retry_base_delay_ms']);
        }
        if (isset($options['min_interval_ms'])) {
            $this->minRequestIntervalMs = max(0, (int) $options['min_interval_ms']);
        }
        if (isset($options['usage_threshold'])) {
            $this->usageThrottleThreshold = max(0, min(100, (int) $options['usage_threshold']));
        }
        if (isset($options['usage_delay_ms'])) {
            $this->usageThrottleDelayMs = max(0, (int) $options['usage_delay_ms']);
        }

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

    /**
     * @return array{call_count?: int, total_cputime?: int, total_time?: int}|null
     */
    public function getLastAppUsage(): ?array
    {
        return $this->lastAppUsage;
    }

    public function get(string $uri, array $query = [], string $api_interface = ''): array
    {
        return $this->request('GET', $uri, $query, [], $api_interface);
    }

    public function post(string $uri, array $body = [], array $query = [], string $api_interface = '', bool $asForm = false): array
    {
        return $this->request('POST', $uri, $query, $body, $api_interface, $asForm);
    }

    public function delete(string $uri, array $query = [], string $api_interface = ''): array
    {
        return $this->request('DELETE', $uri, $query, [], $api_interface);
    }

    /**
     * Graph API Batch 请求（自动按 50 条分片，并走统一限流/重试）。
     *
     * @param list<array{method: string, relative_url: string, body?: string, name?: string, headers?: string|array}> $requests
     * @return list<array{code: int, headers: array, body: mixed, success: bool, error: string}>
     */
    public function batch(array $requests, bool $includeHeaders = false, string $api_interface = 'POST:/?batch'): array
    {
        if ($requests === []) {
            return [];
        }

        $results = [];
        foreach (array_chunk(array_values($requests), self::BATCH_MAX_SIZE) as $chunk) {
            $normalized = [];
            foreach ($chunk as $item) {
                $entry = [
                    'method' => strtoupper((string) ($item['method'] ?? 'GET')),
                    'relative_url' => ltrim((string) ($item['relative_url'] ?? ''), '/'),
                ];
                if (isset($item['body']) && $item['body'] !== '') {
                    $entry['body'] = (string) $item['body'];
                }
                if (isset($item['name']) && $item['name'] !== '') {
                    $entry['name'] = (string) $item['name'];
                }
                if (isset($item['headers'])) {
                    $entry['headers'] = is_array($item['headers'])
                        ? $item['headers']
                        : (string) $item['headers'];
                }
                $normalized[] = $entry;
            }

            // Graph Batch 官方推荐 form 提交；batch 字段为 JSON 字符串
            $raw = $this->post(
                '/',
                [
                    'batch' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                    'include_headers' => $includeHeaders ? 'true' : 'false',
                ],
                [],
                $api_interface,
                true
            );

            // Graph Batch 成功时返回索引数组；异常形态可能是带 error 的关联数组
            if (! array_is_list($raw)) {
                throw new FacebookApiException(
                    (string) ($raw['error']['message'] ?? 'Invalid Facebook batch response'),
                    (int) ($raw['error']['code'] ?? 0),
                    $raw
                );
            }

            foreach ($raw as $item) {
                $code = (int) ($item['code'] ?? 0);
                $bodyRaw = $item['body'] ?? '';
                $decoded = is_string($bodyRaw) ? (json_decode($bodyRaw, true) ?: $bodyRaw) : $bodyRaw;
                $errorMessage = '';
                if (is_array($decoded) && isset($decoded['error']['message'])) {
                    $errorMessage = (string) $decoded['error']['message'];
                }

                $results[] = [
                    'code' => $code,
                    'headers' => $item['headers'] ?? [],
                    'body' => $decoded,
                    'success' => $code >= 200 && $code < 300 && $errorMessage === '',
                    'error' => $errorMessage,
                ];
            }
        }

        return $results;
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
        string $api_interface = '',
        bool $asForm = false
    ): array {
        $attempt = 0;

        while (true) {
            $this->paceBeforeRequest();

            try {
                return $this->sendRequest($method, $uri, $query, $body, $api_interface, $asForm);
            } catch (FacebookApiException $e) {
                if (! $this->isRateLimitError($e) || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                ++$attempt;
                $delayMs = $this->resolveBackoffDelayMs($attempt, $e);
                $this->safeLog(
                    [
                        'attempt' => $attempt,
                        'delay_ms' => $delayMs,
                        'uri' => $uri,
                        'message' => $e->getMessage(),
                        'token' => $this->accessToken,
                    ],
                    'rate-limit-retry',
                    'limit'
                );
                $this->sleepMs($delayMs);
            }
        }
    }

    protected function sendRequest(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        string $api_interface = '',
        bool $asForm = false
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
                if ($asForm) {
                    $options['form_params'] = $body;
                    // 避免业务侧 defaultHeaders 里的 application/json 干扰 form 提交
                    unset($options['headers']['Content-Type'], $options['headers']['content-type']);
                } else {
                    $options['json'] = $body;
                }
            }

            $uri = $this->normalizeUri($uri);

            $response = $this->http->request($method, $uri, $options);
            $this->lastRequestAt = microtime(true);
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
                $this->lastRequestAt = microtime(true);
                $this->captureAppUsage($response, $this->accessToken, $this->busineId, $this->platformId);
                $nowBody = json_decode((string) $response->getBody(), true) ?: [];
                $payload = [
                    ...$nowBody,
                    'token' => $this->accessToken,
                    'headers' => [
                        'retry-after' => $response->getHeaderLine('Retry-After'),
                        'x-app-usage' => $response->getHeaderLine('x-app-usage'),
                        'x-business-use-case-usage' => $response->getHeaderLine('x-business-use-case-usage'),
                        'x-ad-account-usage' => $response->getHeaderLine('x-ad-account-usage'),
                    ],
                ];
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
                (int) ($nowBody['error']['code'] ?? $e->getCode()),
                $payload,
                $e
            );
        }
    }

    protected function paceBeforeRequest(): void
    {
        if ($this->minRequestIntervalMs > 0 && $this->lastRequestAt > 0) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            $remain = $this->minRequestIntervalMs - (int) $elapsedMs;
            if ($remain > 0) {
                $this->sleepMs($remain);
            }
        }

        if ($this->lastAppUsage !== null && $this->usageThrottleDelayMs > 0) {
            $maxUsage = max(
                (int) ($this->lastAppUsage['call_count'] ?? 0),
                (int) ($this->lastAppUsage['total_cputime'] ?? 0),
                (int) ($this->lastAppUsage['total_time'] ?? 0),
            );
            if ($maxUsage >= $this->usageThrottleThreshold) {
                $this->safeLog(
                    [
                        'usage' => $this->lastAppUsage,
                        'threshold' => $this->usageThrottleThreshold,
                        'delay_ms' => $this->usageThrottleDelayMs,
                        'token' => $this->accessToken,
                    ],
                    'usage-throttle',
                    'limit'
                );
                $this->sleepMs($this->usageThrottleDelayMs);
            }
        }
    }

    protected function isRateLimitError(FacebookApiException $e): bool
    {
        $code = $e->getCode();
        if (in_array($code, self::RATE_LIMIT_CODES, true)) {
            return true;
        }

        $response = $e->getResponse();
        $errorCode = (int) ($response['error']['code'] ?? 0);
        if (in_array($errorCode, self::RATE_LIMIT_CODES, true)) {
            return true;
        }

        $message = strtolower($e->getMessage() . ' ' . (string) ($response['error']['message'] ?? ''));
        foreach (['rate limit', 'request limit reached', 'too many calls', 'user request limit'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveBackoffDelayMs(int $attempt, FacebookApiException $e): int
    {
        $retryAfter = (int) ($e->getResponse()['headers']['retry-after'] ?? 0);
        if ($retryAfter > 0) {
            return $retryAfter * 1000;
        }

        // 指数退避 + 少量抖动，避免惊群
        $exp = $this->retryBaseDelayMs * (2 ** max(0, $attempt - 1));
        $jitter = random_int(0, (int) max(1, $this->retryBaseDelayMs / 2));

        return min(30_000, $exp + $jitter);
    }

    protected function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }

        if (
            class_exists('\Hyperf\Coroutine\Coroutine')
            && \Hyperf\Coroutine\Coroutine::inCoroutine()
        ) {
            \Hyperf\Coroutine\Coroutine::sleep($ms / 1000);

            return;
        }

        usleep($ms * 1000);
    }

    protected function captureAppUsage(
        ResponseInterface $response,
        string $accessToken,
        int $busineId,
        int $platformId
    ): void {
        $header = $response->getHeaderLine('x-app-usage');
        if ($header === '') {
            return;
        }
        $usage = json_decode($header, true);
        if (! is_array($usage)) {
            return;
        }

        $this->lastAppUsage = $usage;

        if (is_callable(self::$appUsageHandler)) {
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
