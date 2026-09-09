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
use Goletter\Adv\Platforms\Google\GoogleAuth;
use Goletter\Adv\Platforms\Google\GoogleBusiness;
use Goletter\Adv\Platforms\Google\GoogleCampaign;
use Goletter\Adv\Platforms\Google\GoogleClient;
use Goletter\Adv\Platforms\Google\GoogleReport;
use Goletter\Adv\Platforms\TikTok\TikTokAccount;
use Goletter\Adv\Platforms\TikTok\TikTokAuth;
use Goletter\Adv\Platforms\TikTok\TikTokBusiness;
use Goletter\Adv\Platforms\TikTok\TikTokCampaign;
use Goletter\Adv\Platforms\TikTok\TikTokClient;
use Goletter\Adv\Platforms\TikTok\TikTokReport;
use InvalidArgumentException;

/**
 * 多平台广告 SDK 工厂。
 *
 * 静态用法（兼容）：
 *   AdvFactory::make('facebook', $token)
 *   AdvFactory::facebook($token)   // IDE 类型更精确
 *   AdvFactory::make(0, $token)
 *
 * Hyperf DI：
 *   $factory = $container->get(AdvFactory::class);
 *   $adv = $factory->create('google', $token);
 *
 * 自定义平台：
 *   AdvFactory::register('custom', fn (string $token, array $options): PlatformBundle => ...);
 */
class AdvFactory
{
    public const FACEBOOK = 'facebook';
    public const TIKTOK = 'tiktok';
    public const GOOGLE = 'google';

    /** @var array<int|string, string> */
    protected static array $defaultAliases = [
        0 => self::FACEBOOK,
        1 => self::TIKTOK,
        3 => self::GOOGLE,
        'fb' => self::FACEBOOK,
        'meta' => self::FACEBOOK,
        'tt' => self::TIKTOK,
    ];

    /**
     * @var array<string, callable(string|array, array): PlatformBundle>
     */
    protected static array $creators = [];

    protected static bool $defaultsBooted = false;

    protected static ?self $shared = null;

    /** @var array<string, mixed> */
    protected array $config;

    /** @var array<int|string, string> */
    protected array $aliases;

    /**
     * @param array<string, mixed> $config publish/adv.php 结构
     */
    public function __construct(array $config = [])
    {
        self::bootDefaults();
        $this->config = $config;
        $this->aliases = self::$defaultAliases;
        foreach ($config['platforms'] ?? [] as $key => $name) {
            $this->aliases[$key] = (string) $name;
        }
    }

    /**
     * 供 Hyperf DI 注入同一实例，使静态 make() 也能读到配置。
     */
    public static function setShared(self $factory): void
    {
        self::$shared = $factory;
    }

    public static function getShared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * 注册 / 覆盖平台创建器。
     *
     * @param callable(string|array, array): PlatformBundle $creator
     */
    public static function register(string $platform, callable $creator): void
    {
        self::bootDefaults();
        self::$creators[strtolower(trim($platform))] = $creator;
    }

    public static function has(string $platform): bool
    {
        self::bootDefaults();

        return isset(self::$creators[strtolower(trim($platform))]);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array{
     *     api_version?: string,
     *     base_uri?: string,
     *     developer_token?: string,
     *     login_customer_id?: string,
     *     default_headers?: array,
     *     rate_limit?: array
     * } $options
     * @return (
     *     $platform is 'facebook'|'fb'|'meta'|0
     *         ? PlatformBundle<FacebookClient, FacebookAccount, FacebookBusiness, FacebookCampaign, FacebookReport>
     *         : ($platform is 'tiktok'|'tt'|1
     *             ? PlatformBundle<TikTokClient, TikTokAccount, TikTokBusiness, TikTokCampaign, TikTokReport>
     *             : ($platform is 'google'|3
     *                 ? PlatformBundle<GoogleClient, GoogleAccount, GoogleBusiness, GoogleCampaign, GoogleReport>
     *                 : PlatformBundle))
     * )
     */
    public static function make(string|int $platform, string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::getShared()->create($platform, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<FacebookClient, FacebookAccount, FacebookBusiness, FacebookCampaign, FacebookReport>
     */
    public static function facebook(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::FACEBOOK, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<TikTokClient, TikTokAccount, TikTokBusiness, TikTokCampaign, TikTokReport>
     */
    public static function tiktok(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::TIKTOK, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<GoogleClient, GoogleAccount, GoogleBusiness, GoogleCampaign, GoogleReport>
     */
    public static function google(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::GOOGLE, $accessToken, $options);
    }

    /**
     * 实例方法：合并 config/autoload/adv.php 后创建 Bundle。
     *
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return (
     *     $platform is 'facebook'|'fb'|'meta'|0
     *         ? PlatformBundle<FacebookClient, FacebookAccount, FacebookBusiness, FacebookCampaign, FacebookReport>
     *         : ($platform is 'tiktok'|'tt'|1
     *             ? PlatformBundle<TikTokClient, TikTokAccount, TikTokBusiness, TikTokCampaign, TikTokReport>
     *             : ($platform is 'google'|3
     *                 ? PlatformBundle<GoogleClient, GoogleAccount, GoogleBusiness, GoogleCampaign, GoogleReport>
     *                 : PlatformBundle))
     * )
     */
    public function create(string|int $platform, string|array $accessToken, array $options = []): PlatformBundle
    {
        $name = $this->resolvePlatform($platform);
        $creator = self::$creators[$name] ?? null;
        if ($creator === null) {
            throw new InvalidArgumentException("Unsupported adv platform: {$platform}");
        }

        $merged = array_replace_recursive($this->platformOptions($name), $options);

        return $creator($accessToken, $merged);
    }

    /**
     * Facebook 应用 OAuth（无需已有 user access token）。
     *
     * @param array{api_version?: string} $options
     */
    public static function facebookAuth(array $options = []): FacebookAuth
    {
        return self::getShared()->makeFacebookAuth($options);
    }

    /**
     * TikTok Marketing API OAuth（无需已有 access token）。
     *
     * @param array{base_uri?: string, auth_base_uri?: string} $options
     */
    public static function tiktokAuth(array $options = []): TikTokAuth
    {
        return self::getShared()->makeTikTokAuth($options);
    }

    /**
     * Google Ads OAuth2（无需已有 access token）。
     */
    public static function googleAuth(): GoogleAuth
    {
        return self::getShared()->makeGoogleAuth();
    }

    /**
     * @param array{api_version?: string} $options
     */
    public function makeFacebookAuth(array $options = []): FacebookAuth
    {
        $merged = array_replace_recursive($this->platformOptions(self::FACEBOOK), $options);

        return new FacebookAuth((string) ($merged['api_version'] ?? 'v24.0'));
    }

    /**
     * @param array{base_uri?: string, auth_base_uri?: string} $options
     */
    public function makeTikTokAuth(array $options = []): TikTokAuth
    {
        $merged = array_replace_recursive($this->platformOptions(self::TIKTOK), $options);
        $baseUri = (string) ($merged['base_uri'] ?? 'https://business-api.tiktok.com');

        return new TikTokAuth(
            $baseUri,
            (string) ($merged['auth_base_uri'] ?? $baseUri)
        );
    }

    public function makeGoogleAuth(): GoogleAuth
    {
        return new GoogleAuth();
    }

    public static function normalizePlatform(string|int $platform): string
    {
        return self::getShared()->resolvePlatform($platform);
    }

    public function resolvePlatform(string|int $platform): string
    {
        if (is_int($platform) || ctype_digit((string) $platform)) {
            $key = (int) $platform;
            if (! isset($this->aliases[$key])) {
                throw new InvalidArgumentException("Unknown adv platform id: {$platform}");
            }

            return strtolower((string) $this->aliases[$key]);
        }

        $name = strtolower(trim((string) $platform));
        if (isset($this->aliases[$name])) {
            return strtolower((string) $this->aliases[$name]);
        }

        if (isset(self::$creators[$name]) || in_array($name, [self::FACEBOOK, self::TIKTOK, self::GOOGLE], true)) {
            return $name;
        }

        throw new InvalidArgumentException("Unknown adv platform: {$platform}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function platformOptions(string $platform): array
    {
        $options = $this->config[$platform] ?? [];

        return is_array($options) ? $options : [];
    }

    protected static function bootDefaults(): void
    {
        if (self::$defaultsBooted) {
            return;
        }
        self::$defaultsBooted = true;

        self::$creators[self::FACEBOOK] = static function (string|array $accessToken, array $options): PlatformBundle {
            return self::buildFacebook($accessToken, $options);
        };
        self::$creators[self::TIKTOK] = static function (string|array $accessToken, array $options): PlatformBundle {
            return self::buildTikTok($accessToken, $options);
        };
        self::$creators[self::GOOGLE] = static function (string|array $accessToken, array $options): PlatformBundle {
            return self::buildGoogle($accessToken, $options);
        };
    }

    /**
     * @param string|list<string> $accessToken
     * @return PlatformBundle<FacebookClient, FacebookAccount, FacebookBusiness, FacebookCampaign, FacebookReport>
     */
    protected static function buildFacebook(string|array $accessToken, array $options): PlatformBundle
    {
        $client = new FacebookClient(
            $accessToken,
            (string) ($options['api_version'] ?? 'v24.0')
        );

        if (! empty($options['default_headers']) && is_array($options['default_headers'])) {
            $client->setDefaultHeaders($options['default_headers']);
        }

        if (! empty($options['rate_limit']) && is_array($options['rate_limit'])) {
            $client->configureRateLimit($options['rate_limit']);
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

    /**
     * @param string|list<string> $accessToken
     * @return PlatformBundle<TikTokClient, TikTokAccount, TikTokBusiness, TikTokCampaign, TikTokReport>
     */
    protected static function buildTikTok(string|array $accessToken, array $options): PlatformBundle
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

    /**
     * @param string|list<string> $accessToken
     * @return PlatformBundle<GoogleClient, GoogleAccount, GoogleBusiness, GoogleCampaign, GoogleReport>
     */
    protected static function buildGoogle(string|array $accessToken, array $options): PlatformBundle
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
