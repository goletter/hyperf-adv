<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

use GuzzleHttp\Client;
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokApiException;
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokTokenExpiredException;
use GuzzleHttp\Exception\RequestException;

class TikTokClient
{
    protected Client $http;
    protected string $accessToken;
    protected string $baseUri;

    protected array $defaultHeaders = [];

    public function __construct(
        string $accessToken,
        string $baseUri = 'https://business-api.tiktok.com'
    ) {
        $this->accessToken = $accessToken;
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

    public function get(string $uri, array $query = []): array
    {
        return $this->request('GET', $uri, $query);
    }

    public function post(string $uri, array $body = [], array $query = []): array
    {
        return $this->request('POST', $uri, $query, $body);
    }

    public function delete(string $uri, array $query = []): array
    {
        return $this->request('DELETE', $uri, $query);
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
        array $body = []
    ): array {
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

            return $data;
        } catch (TikTokApiException $e) {
            throw $e;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response === null) {
                throw new TikTokApiException($e->getMessage(), (int) $e->getCode(), [], $e);
            }

            $decoded = json_decode((string) $response->getBody(), true) ?: [];
            $payload = [...$decoded, 'token' => $this->accessToken];

            throw new TikTokApiException(
                json_encode($payload, JSON_UNESCAPED_UNICODE) ?: $e->getMessage(),
                (int) $e->getCode(),
                $decoded,
                $e
            );
        }
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
