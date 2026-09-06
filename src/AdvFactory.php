<?php

declare(strict_types=1);

namespace Goletter\Adv;

use Goletter\Adv\Platforms\Facebook\FacebookAccount;
use Goletter\Adv\Platforms\Facebook\FacebookAuth;
use Goletter\Adv\Platforms\Facebook\FacebookBusiness;
use Goletter\Adv\Platforms\Facebook\FacebookCampaign;
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookReport;
use Goletter\Adv\Platforms\Google\GoogleAccount;
use Goletter\Adv\Platforms\Google\GoogleBusiness;
use Goletter\Adv\Platforms\Google\GoogleCampaign;
use Goletter\Adv\Platforms\Google\GoogleClient;
use Goletter\Adv\Platforms\Google\GoogleReport;
use Goletter\Adv\Platforms\TikTok\TikTokAccount;
use Goletter\Adv\Platforms\TikTok\TikTokBusiness;
use Goletter\Adv\Platforms\TikTok\TikTokCampaign;
use Goletter\Adv\Platforms\TikTok\TikTokClient;
use Goletter\Adv\Platforms\TikTok\TikTokReport;
use InvalidArgumentException;

/**
 * 多平台广告 SDK 工厂。
 *
 * 用法：
 *   AdvFactory::make('facebook', $token)
 *   AdvFactory::make(1, $token, ['business_id' => 123])
 *   AdvFactory::make('google', $token, ['developer_token' => '...', 'login_customer_id' => '...'])
 */
class AdvFactory
{
    public const FACEBOOK = 'facebook';
    public const TIKTOK = 'tiktok';
    public const GOOGLE = 'google';

    /** @var array<int|string, string> */
    protected static array $aliases = [
        1 => self::FACEBOOK,
        2 => self::GOOGLE,
        3 => self::TIKTOK,
        'fb' => self::FACEBOOK,
        'meta' => self::FACEBOOK,
        'tt' => self::TIKTOK,
    ];

    /**
     * @param array{
     *     business_id?: int,
     *     platform_id?: int,
     *     api_version?: string,
     *     base_uri?: string,
     *     developer_token?: string,
     *     login_customer_id?: string,
     *     default_headers?: array
     * } $options
     */
    public static function make(string|int $platform, string $accessToken, array $options = []): PlatformBundle
    {
        $name = self::normalizePlatform($platform);

        return match ($name) {
            self::FACEBOOK => self::makeFacebook($accessToken, $options),
            self::TIKTOK => self::makeTikTok($accessToken, $options),
            self::GOOGLE => self::makeGoogle($accessToken, $options),
            default => throw new InvalidArgumentException("Unsupported adv platform: {$platform}"),
        };
    }

    /**
     * Facebook 应用 OAuth（无需已有 user access token）。
     *
     * @param array{api_version?: string} $options
     */
    public static function facebookAuth(array $options = []): FacebookAuth
    {
        return new FacebookAuth((string) ($options['api_version'] ?? 'v24.0'));
    }

    public static function normalizePlatform(string|int $platform): string
    {
        if (is_int($platform) || ctype_digit((string) $platform)) {
            $key = (int) $platform;
            if (! isset(self::$aliases[$key])) {
                throw new InvalidArgumentException("Unknown adv platform id: {$platform}");
            }

            return self::$aliases[$key];
        }

        $name = strtolower(trim((string) $platform));
        if (isset(self::$aliases[$name])) {
            return self::$aliases[$name];
        }

        if (in_array($name, [self::FACEBOOK, self::TIKTOK, self::GOOGLE], true)) {
            return $name;
        }

        throw new InvalidArgumentException("Unknown adv platform: {$platform}");
    }

    protected static function makeFacebook(string $accessToken, array $options): PlatformBundle
    {
        $client = new FacebookClient(
            $accessToken,
            (int) ($options['business_id'] ?? $options['busine_id'] ?? 0),
            (int) ($options['platform_id'] ?? 0),
            (string) ($options['api_version'] ?? 'v24.0')
        );

        if (! empty($options['default_headers']) && is_array($options['default_headers'])) {
            $client->setDefaultHeaders($options['default_headers']);
        }

        return new PlatformBundle(
            client: $client,
            account: new FacebookAccount($client),
            business: new FacebookBusiness($client),
            campaign: new FacebookCampaign($client),
            report: new FacebookReport($client),
            platform: self::FACEBOOK,
        );
    }

    protected static function makeTikTok(string $accessToken, array $options): PlatformBundle
    {
        $client = new TikTokClient(
            $accessToken,
            (string) ($options['base_uri'] ?? 'https://business-api.tiktok.com')
        );

        if (! empty($options['default_headers']) && is_array($options['default_headers'])) {
            $client->setDefaultHeaders($options['default_headers']);
        }

        return new PlatformBundle(
            client: $client,
            account: new TikTokAccount($client),
            business: new TikTokBusiness($client),
            campaign: new TikTokCampaign($client),
            report: new TikTokReport($client),
            platform: self::TIKTOK,
        );
    }

    protected static function makeGoogle(string $accessToken, array $options): PlatformBundle
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

        return new PlatformBundle(
            client: $client,
            account: new GoogleAccount($client),
            business: new GoogleBusiness($client),
            campaign: new GoogleCampaign($client),
            report: new GoogleReport($client),
            platform: self::GOOGLE,
        );
    }

    protected static function env(string $key, string $default = ''): string
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
