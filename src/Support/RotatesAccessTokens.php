<?php

declare(strict_types=1);

namespace Goletter\Adv\Support;

use Goletter\Adv\Exceptions\AdvApiException;
use Goletter\Adv\Exceptions\TokenExpiredExceptionInterface;
use Throwable;

/**
 * 多 Access Token 轮询：当前 token 请求失败时切换下一个，直到全部试完。
 *
 * 成功后会粘滞在当前可用 token，下次请求从该 token 继续。
 */
trait RotatesAccessTokens
{
    /** @var list<string> */
    protected array $accessTokens = [];

    protected int $tokenIndex = 0;

    protected string $accessToken = '';

    /**
     * @param string|list<string> $accessToken
     */
    protected function bootstrapAccessTokens(string|array $accessToken): void
    {
        $list = is_array($accessToken) ? $accessToken : [$accessToken];
        $normalized = [];
        foreach ($list as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            if (! in_array($token, $normalized, true)) {
                $normalized[] = $token;
            }
        }

        // 允许空 token（如部分 OAuth / 调试场景）
        if ($normalized === []) {
            $normalized = [is_string($accessToken) ? (string) $accessToken : ''];
        }

        $this->accessTokens = $normalized;
        $this->tokenIndex = 0;
        $this->syncCurrentAccessToken();
    }

    protected function syncCurrentAccessToken(): void
    {
        $this->accessToken = $this->accessTokens[$this->tokenIndex] ?? '';
    }

    /**
     * @return list<string>
     */
    public function getAccessTokens(): array
    {
        return $this->accessTokens;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getAccessTokenIndex(): int
    {
        return $this->tokenIndex;
    }

    /**
     * @param string|list<string> $accessToken
     */
    public function setAccessTokens(string|array $accessToken): static
    {
        $this->bootstrapAccessTokens($accessToken);

        return $this;
    }

    /**
     * 从当前 index 起依次尝试；失败则下一个，全部失败抛最后一次异常。
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    protected function withTokenFailover(callable $operation): mixed
    {
        $count = count($this->accessTokens);
        if ($count <= 1) {
            return $operation();
        }

        $start = $this->tokenIndex;
        $last = null;

        for ($i = 0; $i < $count; ++$i) {
            $this->tokenIndex = ($start + $i) % $count;
            $this->syncCurrentAccessToken();

            try {
                return $operation();
            } catch (Throwable $e) {
                $last = $e;
                if ($i === $count - 1 || ! $this->shouldFailoverAccessToken($e)) {
                    throw $e;
                }
                $this->onAccessTokenFailover($e, $this->accessToken, $i + 1, $count);
            }
        }

        throw $last ?? new \RuntimeException('Access token failover exhausted');
    }

    /**
     * 是否因该异常切换下一个 token。
     * 默认：鉴权失效或 Adv API 异常都会切换。
     */
    protected function shouldFailoverAccessToken(Throwable $e): bool
    {
        return $e instanceof TokenExpiredExceptionInterface
            || $e instanceof AdvApiException;
    }

    /**
     * Token 切换钩子（子类可打日志）。
     */
    protected function onAccessTokenFailover(
        Throwable $e,
        string $failedToken,
        int $tried,
        int $total
    ): void {
    }
}
