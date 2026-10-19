<?php

declare(strict_types=1);

namespace Goletter\Adv;

use InvalidArgumentException;

/**
 * 多平台广告 SDK 工厂。
 *
 * 平台实现在独立扩展包中，并注册到这里：
 *   goletter/hyperf-adv-facebook
 *   goletter/hyperf-adv-tiktok
 *   goletter/hyperf-adv-google
 *   goletter/hyperf-adv-akm
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
    public const AKM = 'akm';

    /** @var array<string, string> */
    protected static array $platformPackages = [
        self::FACEBOOK => 'goletter/hyperf-adv-facebook',
        self::TIKTOK => 'goletter/hyperf-adv-tiktok',
        self::GOOGLE => 'goletter/hyperf-adv-google',
        self::AKM => 'goletter/hyperf-adv-akm',
    ];

    /** @var array<string, class-string> */
    protected static array $platformClasses = [
        self::FACEBOOK => 'Goletter\\Adv\\Platforms\\Facebook\\FacebookPlatform',
        self::TIKTOK => 'Goletter\\Adv\\Platforms\\TikTok\\TikTokPlatform',
        self::GOOGLE => 'Goletter\\Adv\\Platforms\\Google\\GooglePlatform',
        self::AKM => 'Goletter\\Adv\\Platforms\\Akm\\AkmPlatform',
    ];

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
        if (! self::$defaultsBooted) {
            self::bootDefaults();
        }
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
     */
    public static function make(string|int $platform, string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::getShared()->create($platform, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<\Goletter\Adv\Platforms\Facebook\FacebookClient, \Goletter\Adv\Platforms\Facebook\FacebookAccount, \Goletter\Adv\Platforms\Facebook\FacebookBusiness, \Goletter\Adv\Platforms\Facebook\FacebookCampaign, \Goletter\Adv\Platforms\Facebook\FacebookReport>
     */
    public static function facebook(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::FACEBOOK, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<\Goletter\Adv\Platforms\TikTok\TikTokClient, \Goletter\Adv\Platforms\TikTok\TikTokAccount, \Goletter\Adv\Platforms\TikTok\TikTokBusiness, \Goletter\Adv\Platforms\TikTok\TikTokCampaign, \Goletter\Adv\Platforms\TikTok\TikTokReport>
     */
    public static function tiktok(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::TIKTOK, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<\Goletter\Adv\Platforms\Google\GoogleClient, \Goletter\Adv\Platforms\Google\GoogleAccount, \Goletter\Adv\Platforms\Google\GoogleBusiness, \Goletter\Adv\Platforms\Google\GoogleCampaign, \Goletter\Adv\Platforms\Google\GoogleReport>
     */
    public static function google(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::GOOGLE, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     * @return PlatformBundle<\Goletter\Adv\Platforms\Akm\AkmClient, \Goletter\Adv\Platforms\Akm\ApplicationApi, \Goletter\Adv\Platforms\Akm\OpenAccountApi, \Goletter\Adv\Platforms\Akm\ApplicationApi, \Goletter\Adv\Platforms\Akm\DashboardApi>
     */
    public static function akm(string|array $accessToken, array $options = []): PlatformBundle
    {
        return self::make(self::AKM, $accessToken, $options);
    }

    /**
     * 实例方法：合并 config/autoload/adv.php 后创建 Bundle。
     *
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public function create(string|int $platform, string|array $accessToken, array $options = []): PlatformBundle
    {
        $name = $this->resolvePlatform($platform);
        $creator = self::$creators[$name] ?? null;
        if ($creator === null) {
            $package = self::$platformPackages[$name] ?? null;
            if ($package !== null) {
                throw new InvalidArgumentException("Adv platform \"{$name}\" is not installed. Require {$package}.");
            }

            throw new InvalidArgumentException("Unsupported adv platform: {$platform}");
        }

        $merged = array_replace_recursive($this->platformOptions($name), $options);

        return $creator($accessToken, $merged);
    }

    /**
     * Facebook 应用 OAuth（无需已有 user access token）。
     *
     * @param array{api_version?: string} $options
     * @return \Goletter\Adv\Platforms\Facebook\FacebookAuth
     */
    public static function facebookAuth(array $options = []): object
    {
        $platform = self::platformClass(self::FACEBOOK);

        return $platform::auth(self::getShared()->platformConfig(self::FACEBOOK, $options));
    }

    /**
     * TikTok Marketing API OAuth（无需已有 access token）。
     *
     * @param array{base_uri?: string, auth_base_uri?: string} $options
     * @return \Goletter\Adv\Platforms\TikTok\TikTokAuth
     */
    public static function tiktokAuth(array $options = []): object
    {
        $platform = self::platformClass(self::TIKTOK);

        return $platform::auth(self::getShared()->platformConfig(self::TIKTOK, $options));
    }

    /**
     * Google Ads OAuth2（无需已有 access token）。
     *
     * @return \Goletter\Adv\Platforms\Google\GoogleAuth
     */
    public static function googleAuth(): object
    {
        $platform = self::platformClass(self::GOOGLE);

        return $platform::auth();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function platformConfig(string $platform, array $options = []): array
    {
        return array_replace_recursive($this->platformOptions($this->resolvePlatform($platform)), $options);
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

        if (isset(self::$creators[$name]) || in_array($name, [self::FACEBOOK, self::TIKTOK, self::GOOGLE, self::AKM], true)) {
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

        foreach (self::$platformClasses as $class) {
            if (class_exists($class)) {
                $class::register();
            }
        }
    }

    /**
     * @return class-string
     */
    protected static function platformClass(string $platform): string
    {
        self::bootDefaults();
        $class = self::$platformClasses[$platform] ?? '';
        if ($class === '' || ! class_exists($class)) {
            $package = self::$platformPackages[$platform] ?? $platform;

            throw new InvalidArgumentException("Adv platform \"{$platform}\" is not installed. Require {$package}.");
        }

        return $class;
    }
}
