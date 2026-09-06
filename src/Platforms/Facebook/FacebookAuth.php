<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\Platforms\Facebook\Exceptions\FacebookApiException;
use Goletter\Adv\Platforms\Facebook\Exceptions\FacebookTokenExpiredException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Facebook 应用 OAuth 授权。
 *
 * 1. dialog/oauth 生成授权页 URL
 * 2. 回调用 authorization code 换 Access Token（可再换长期 Token）
 */
class FacebookAuth
{
    public const DEFAULT_SCOPES = [
        'ads_management',
        'ads_read',
        'business_management',
        'email',
        'public_profile',
    ];

    protected Client $http;

    protected string $apiVersion;

    protected string $dialogBaseUri = 'https://www.facebook.com';

    protected string $graphBaseUri = 'https://graph.facebook.com';

    public function __construct(string $apiVersion = 'v24.0')
    {
        $this->apiVersion = $apiVersion;
        $this->http = new Client([
            'timeout' => 60,
        ]);
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * 生成应用授权 URL
     * https://www.facebook.com/{version}/dialog/oauth
     *
     * @param string|array|null $scope 权限，默认广告管理相关 scope；数组会用逗号拼接
     * @param array<string, scalar|null> $extra 额外 query 参数（如 auth_type、config_id 等）
     */
    public function getAuthUrl(
        string $clientId,
        string $redirectUri,
        ?string $state = null,
        string|array|null $scope = null,
        string $responseType = 'code',
        array $extra = []
    ): string {
        $query = array_filter([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => $responseType,
            'scope' => $this->normalizeScope($scope),
            'state' => $state,
            ...$extra,
        ], static fn ($value) => $value !== null && $value !== '');

        return sprintf(
            '%s/%s/dialog/oauth?%s',
            rtrim($this->dialogBaseUri, '/'),
            $this->apiVersion,
            http_build_query($query)
        );
    }

    /**
     * OAuth 回调：authorization code 换短期 User Access Token
     * GET https://graph.facebook.com/{version}/oauth/access_token
     *
     * @return array{access_token: string, token_type?: string, expires_in?: int, ...}
     */
    public function fetchToken(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code
    ): array {
        return $this->requestOAuth([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
    }

    /**
     * 短期 Token 换长期 Token（约 60 天）
     * GET /oauth/access_token?grant_type=fb_exchange_token
     *
     * @return array{access_token: string, token_type?: string, expires_in?: int, ...}
     */
    public function exchangeLongLivedToken(
        string $clientId,
        string $clientSecret,
        string $shortLivedToken
    ): array {
        return $this->requestOAuth([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);
    }

    /**
     * OAuth 回调一站式处理：code → 短期 Token → 长期 Token
     *
     * @return array{
     *     access_token: string,
     *     token_type?: string,
     *     expires_in?: int,
     *     short_lived?: array,
     *     long_lived?: array,
     *     ...
     * }
     */
    public function handleCallback(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code,
        bool $exchangeLongLived = true
    ): array {
        $shortLived = $this->fetchToken($clientId, $clientSecret, $redirectUri, $code);
        $shortToken = (string) ($shortLived['access_token'] ?? '');
        if ($shortToken === '') {
            throw new FacebookApiException('Missing access_token in OAuth response', 400, $shortLived);
        }

        if (! $exchangeLongLived) {
            return $shortLived + ['short_lived' => $shortLived];
        }

        $longLived = $this->exchangeLongLivedToken($clientId, $clientSecret, $shortToken);

        return array_merge($longLived, [
            'access_token' => (string) ($longLived['access_token'] ?? $shortToken),
            'expires_in' => $longLived['expires_in'] ?? ($shortLived['expires_in'] ?? null),
            'short_lived' => $shortLived,
            'long_lived' => $longLived,
        ]);
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    protected function requestOAuth(array $query): array
    {
        $uri = sprintf('%s/%s/oauth/access_token', rtrim($this->graphBaseUri, '/'), $this->apiVersion);

        try {
            $response = $this->http->get($uri, [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true) ?: [];

            if (isset($data['error'])) {
                $this->handleError(is_array($data['error']) ? $data['error'] : [], $data);
            }

            return $data;
        } catch (FacebookApiException $e) {
            throw $e;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $body = $response ? (json_decode((string) $response->getBody(), true) ?: []) : [];
            if (isset($body['error']) && is_array($body['error'])) {
                $this->handleError($body['error'], $body);
            }

            throw new FacebookApiException(
                (string) ($body['error']['message'] ?? $e->getMessage()),
                (int) ($body['error']['code'] ?? $e->getCode()),
                $body,
                $e
            );
        }
    }

    /**
     * @param string|array|null $scope
     */
    protected function normalizeScope(string|array|null $scope): string
    {
        if ($scope === null) {
            return implode(',', self::DEFAULT_SCOPES);
        }

        if (is_array($scope)) {
            return implode(',', array_values(array_filter(array_map('strval', $scope))));
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $error
     * @param array<string, mixed> $raw
     */
    protected function handleError(array $error, array $raw): void
    {
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? 'Facebook OAuth error');

        if ($code === 190) {
            throw new FacebookTokenExpiredException($message, $code, $raw);
        }

        throw new FacebookApiException($message, $code, $raw);
    }
}
