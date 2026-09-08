<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

use Goletter\Adv\Platforms\Google\Exceptions\GoogleApiException;
use Goletter\Adv\Platforms\Google\Exceptions\GoogleTokenExpiredException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Google Ads API OAuth2 授权。
 *
 * 1. accounts.google.com/o/oauth2/v2/auth 生成授权页 URL
 * 2. 回调用 authorization code 换 Access Token / Refresh Token
 */
class GoogleAuth
{
    public const DEFAULT_SCOPES = [
        'https://www.googleapis.com/auth/adwords',
    ];

    private const AUTHORIZE_URI = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 60,
        ]);
    }

    /**
     * 生成应用授权 URL
     * https://accounts.google.com/o/oauth2/v2/auth
     *
     * @param string|array|null $scope 默认 Google Ads（adwords）；数组会用空格拼接
     * @param array<string, scalar|null> $extra 额外 query 参数
     */
    public function getAuthUrl(
        string $clientId,
        string $redirectUri,
        ?string $state = null,
        string|array|null $scope = null,
        string $accessType = 'offline',
        string $prompt = 'consent',
        string $responseType = 'code',
        array $extra = []
    ): string {
        $query = array_filter([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => $responseType,
            'scope' => $this->normalizeScope($scope),
            'access_type' => $accessType,
            'prompt' => $prompt,
            'include_granted_scopes' => 'true',
            'state' => $state,
            ...$extra,
        ], static fn ($value) => $value !== null && $value !== '');

        return self::AUTHORIZE_URI . '?' . http_build_query($query);
    }

    /**
     * OAuth 回调：authorization code 换 Access Token
     * POST https://oauth2.googleapis.com/token
     *
     * @return array{
     *     access_token: string,
     *     expires_in?: int,
     *     refresh_token?: string,
     *     scope?: string,
     *     token_type?: string,
     *     ...
     * }
     */
    public function fetchToken(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code
    ): array {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * 使用 refresh_token 刷新 Access Token
     * POST https://oauth2.googleapis.com/token
     *
     * @return array{
     *     access_token: string,
     *     expires_in?: int,
     *     scope?: string,
     *     token_type?: string,
     *     refresh_token?: string,
     *     ...
     * }
     */
    public function refreshToken(
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): array {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
    }

    /**
     * OAuth 回调一站式：code → Access Token（含 refresh_token，若首次 consent）
     *
     * @return array{
     *     access_token: string,
     *     expires_in?: int,
     *     refresh_token?: string,
     *     scope?: string,
     *     token_type?: string,
     *     ...
     * }
     */
    public function handleCallback(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code
    ): array {
        $token = $this->fetchToken($clientId, $clientSecret, $redirectUri, $code);
        if (empty($token['access_token'])) {
            throw new GoogleApiException('Missing access_token in OAuth response', 400, $token);
        }

        return $token;
    }

    /**
     * @param array<string, scalar> $form
     * @return array<string, mixed>
     */
    protected function requestToken(array $form): array
    {
        try {
            $response = $this->http->post(self::TOKEN_URI, [
                'form_params' => $form,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true) ?: [];

            return $this->assertTokenResponse($payload);
        } catch (GoogleApiException $e) {
            throw $e;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $payload = $response ? (json_decode((string) $response->getBody(), true) ?: []) : [];
            if ($payload !== []) {
                return $this->assertTokenResponse($payload);
            }

            throw new GoogleApiException($e->getMessage(), (int) $e->getCode(), $payload, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function assertTokenResponse(array $payload): array
    {
        if (isset($payload['error'])) {
            $error = (string) $payload['error'];
            $message = (string) ($payload['error_description'] ?? $error);
            $status = in_array($error, ['invalid_grant', 'invalid_token', 'expired_token'], true) ? 401 : 400;

            if ($status === 401) {
                throw new GoogleTokenExpiredException($message, $status, $payload);
            }

            throw new GoogleApiException($message, $status, $payload);
        }

        if (empty($payload['access_token'])) {
            throw new GoogleApiException('Missing access_token in OAuth response', 400, $payload);
        }

        return $payload;
    }

    /**
     * @param string|array|null $scope
     */
    protected function normalizeScope(string|array|null $scope): string
    {
        if ($scope === null) {
            return implode(' ', self::DEFAULT_SCOPES);
        }

        if (is_array($scope)) {
            return implode(' ', array_values(array_filter(array_map('strval', $scope))));
        }

        return $scope;
    }
}
