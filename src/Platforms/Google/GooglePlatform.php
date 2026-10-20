<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

use Goletter\Adv\AdvFactory;

/**
 * 把 Google Ads 注册到 AdvFactory。
 */
class GooglePlatform
{
    public static function register(): void
    {
        AdvFactory::register(AdvFactory::GOOGLE, [self::class, 'create']);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public static function create(string|array $accessToken, array $options): GoogleBundle
    {
        $developerToken = (string) (
            $options['developer_token']
            ?? self::env('GOOGLE_ADS_DEVELOPER_TOKEN', '')
        );
        $loginCustomerId = (string) (
            $options['login_customer_id']
            ?? self::env('GOOGLE_ADS_LOGIN_CUSTOMER_ID', '')
        );

        $client = new GoogleClient(
            accessToken: $accessToken,
            developerToken: $developerToken,
            loginCustomerId: $loginCustomerId,
            apiVersion: (string) ($options['api_version'] ?? 'v19'),
            baseUri: (string) ($options['base_uri'] ?? 'https://googleads.googleapis.com')
        );

        if (! empty($options['default_headers']) && is_array($options['default_headers'])) {
            $client->setDefaultHeaders($options['default_headers']);
        }

        return new GoogleBundle(
            client: $client,
            account: new GoogleAccount($client),
            business: new GoogleBusiness($client),
            campaign: new GoogleCampaign($client),
            report: new GoogleReport($client),
            platform: AdvFactory::GOOGLE,
        );
    }

    /**
     * @param array<string, mixed> $options 预留；当前 GoogleAuth 无构造参数
     */
    public static function auth(array $options = []): GoogleAuth
    {
        unset($options);

        return new GoogleAuth();
    }

    private static function env(string $key, string $default = ''): string
    {
        if (function_exists('Hyperf\\Support\\env')) {
            return (string) \Hyperf\Support\env($key, $default);
        }

        $value = getenv($key);
        if ($value === false) {
            return (string) ($_ENV[$key] ?? $default);
        }

        return (string) $value;
    }
}
