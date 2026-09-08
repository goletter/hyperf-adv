<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokApiException;
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokTokenExpiredException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * TikTok Marketing API 应用 OAuth 授权。
 *
 * 1. portal/auth 生成授权页 URL
 * 2. 回调用 auth_code 换 Access Token / Refresh Token
 */
class TikTokAuth
{
    private const ACCESS_TOKEN_PATH = '/open_api/v1.3/oauth2/access_token/';

    private const REFRESH_TOKEN_PATH = '/open_api/v1.3/oauth2/refresh_token/';

    protected Client $http;

    protected string $baseUri;

    protected string $authBaseUri;

    public function __construct(
        string $baseUri = 'https://business-api.tiktok.com',
        string $authBaseUri = 'https://business-api.tiktok.com'
    ) {
        $this->baseUri = rtrim($baseUri, '/');
        $this->authBaseUri = rtrim($authBaseUri, '/');
        $this->http = new Client([
            'timeout' => 60,
        ]);
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    /**
     * 生成应用授权 URL
     * https://business-api.tiktok.com/portal/auth?app_id=&state=&redirect_uri=
     *
     * @param array<string, scalar|null> $extra 额外 query 参数
     */
    public function getAuthUrl(
        string $appId,
        string $redirectUri,
        ?string $state = null,
        array $extra = []
    ): string {
        $query = array_filter([
            'app_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            ...$extra,
        ], static fn ($value) => $value !== null && $value !== '');

        return sprintf(
            '%s/portal/auth?%s',
            $this->authBaseUri,
            http_build_query($query)
        );
    }

    /**
     * OAuth 回调：auth_code 换 Access Token
     * POST /open_api/v1.3/oauth2/access_token/
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     refresh_expires_in?: int,
     *     advertiser_ids?: list<string|int>,
     *     scope?: list<int>|string,
     *     token_type?: string,
     *     ...
     * }
     */
    public function fetchToken(string $appId, string $secret, string $authCode): array
    {
        return $this->requestOAuth(self::ACCESS_TOKEN_PATH, [
            'app_id' => $appId,
            'secret' => $secret,
            'auth_code' => $authCode,
        ]);
    }

    /**
     * 使用 refresh_token 刷新 Access Token
     * POST /open_api/v1.3/oauth2/refresh_token/
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     refresh_expires_in?: int,
     *     advertiser_ids?: list<string|int>,
     *     scope?: list<int>|string,
     *     token_type?: string,
     *     ...
     * }
     */
    public function refreshToken(string $appId, string $secret, string $refreshToken): array
    {
        return $this->requestOAuth(self::REFRESH_TOKEN_PATH, [
            'app_id' => $appId,
            'secret' => $secret,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * OAuth 回调一站式：auth_code → Access Token
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     refresh_expires_in?: int,
     *     advertiser_ids?: list<string|int>,
     *     ...
     * }
     */
    public function handleCallback(string $appId, string $secret, string $authCode): array
    {
        $token = $this->fetchToken($appId, $secret, $authCode);
        if (empty($token['access_token'])) {
            throw new TikTokApiException('Missing access_token in OAuth response', 400, $token);
        }

        return $token;
    }

    /**
     * @param array<string, scalar> $body
     * @return array<string, mixed>
     */
    protected function requestOAuth(string $path, array $body): array
    {
        $uri = $this->baseUri . $path;

        try {
            $response = $this->http->post($uri, [
                'json' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true) ?: [];

            return $this->assertTokenResponse($payload);
        } catch (TikTokApiException $e) {
            throw $e;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $payload = $response ? (json_decode((string) $response->getBody(), true) ?: []) : [];
            if ($payload !== []) {
                return $this->assertTokenResponse($payload);
            }

            throw new TikTokApiException($e->getMessage(), (int) $e->getCode(), $payload, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function assertTokenResponse(array $payload): array
    {
        $code = isset($payload['code']) ? (int) $payload['code'] : 0;
        if ($code !== 0) {
            $message = (string) ($payload['message'] ?? 'TikTok OAuth error');
            if (in_array($code, [40100, 40101, 40102], true)) {
                throw new TikTokTokenExpiredException($message, $code, $payload);
            }

            throw new TikTokApiException($message, $code, $payload);
        }

        $data = $payload['data'] ?? $payload;
        if (! is_array($data)) {
            throw new TikTokApiException('Invalid TikTok OAuth response', 400, $payload);
        }

        return $data;
    }
}
