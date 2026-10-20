<?php

declare(strict_types=1);

namespace Goletter\Adv;

use Goletter\Adv\Platforms\Facebook\FacebookAuth;
use Goletter\Adv\Platforms\Facebook\FacebookBundle;
use Goletter\Adv\Platforms\Google\GoogleAuth;
use Goletter\Adv\Platforms\Google\GoogleBundle;
use Goletter\Adv\Platforms\TikTok\TikTokAuth;
use Goletter\Adv\Platforms\TikTok\TikTokBundle;
use InvalidArgumentException;

/**
 * 多平台广告 SDK 工厂。
 *
 * 本包内置 Facebook / TikTok / Google。
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

    /** @var array<string, class-string> */
    protected static array $platformClasses = [
        self::FACEBOOK => 'Goletter\\Adv\\Platforms\\Facebook\\FacebookPlatform',
        self::TIKTOK => 'Goletter\\Adv\\Platforms\\TikTok\\TikTokPlatform',
        self::GOOGLE => 'Goletter\\Adv\\Platforms\\Google\\GooglePlatform',
    ];

    /** @var array<int|string, string> */
    protected static array $defaultAliases = [
        0 => self::FACEBOOK,
        1 => self::TIKTOK,
        3 => self::GOOGLE,
        'fb' => self::FACEBOOK,
        'meta' => self::FACEBOOK,
        'tt' => self::TIKTOK,
        'gads' => self::GOOGLE,
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
        self::boot();
        self::$creators[strtolower(trim($platform))] = $creator;
    }

    public static function has(string $platform): bool
    {
        self::boot();

        return isset(self::$creators[strtolower(trim($platform))]);
    }

    /**
     * 已注册平台名列表（按注册顺序）。
     *
     * @return list<string>
     */
    public static function platforms(): array
    {
        self::boot();

        return array_keys(self::$creators);
    }

    /**
     * 显式引导默认平台注册（Hyperf ConfigProvider / 非 DI 场景均可调用）。
     */
    public static function boot(): void
    {
        self::bootDefaults();
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
    public static function make(string|int $platform, string|array $accessToken, array $options = []): PlatformBundle|FacebookBundle|TikTokBundle|GoogleBundle
    {
        return self::getShared()->create($platform, $accessToken, $options);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public static function facebook(string|array $accessToken, array $options = []): FacebookBundle
    {
        $bundle = self::make(self::FACEBOOK, $accessToken, $options);
        if (! $bundle instanceof FacebookBundle) {
            throw new InvalidArgumentException('Facebook platform is not available.');
        }

        return $bundle;
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public static function tiktok(string|array $accessToken, array $options = []): TikTokBundle
    {
        $bundle = self::make(self::TIKTOK, $accessToken, $options);
        if (! $bundle instanceof TikTokBundle) {
            throw new InvalidArgumentException('TikTok platform is not available.');
        }

        return $bundle;
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public static function google(string|array $accessToken, array $options = []): GoogleBundle
    {
        $bundle = self::make(self::GOOGLE, $accessToken, $options);
        if (! $bundle instanceof GoogleBundle) {
            throw new InvalidArgumentException('Google platform is not available.');
        }

        return $bundle;
    }

    /**
     * 实例方法：合并 config/autoload/adv.php 后创建 Bundle。
     *
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public function create(string|int $platform, string|array $accessToken, array $options = []): PlatformBundle|FacebookBundle|TikTokBundle|GoogleBundle
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
        $platform = self::platformClass(self::FACEBOOK);

        return $platform::auth(self::getShared()->platformConfig(self::FACEBOOK, $options));
    }

    /**
     * TikTok Marketing API OAuth（无需已有 access token）。
     *
     * @param array{base_uri?: string, auth_base_uri?: string} $options
     */
    public static function tiktokAuth(array $options = []): TikTokAuth
    {
        $platform = self::platformClass(self::TIKTOK);

        return $platform::auth(self::getShared()->platformConfig(self::TIKTOK, $options));
    }

    /**
     * Google Ads OAuth2（无需已有 access token）。
     *
     * @param array<string, mixed> $options 预留与其它平台 Auth 签名一致；当前 GoogleAuth 无构造参数
     */
    public static function googleAuth(array $options = []): GoogleAuth
    {
        $platform = self::platformClass(self::GOOGLE);

        return $platform::auth(self::getShared()->platformConfig(self::GOOGLE, $options));
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
        self::boot();
        $class = self::$platformClasses[$platform] ?? '';
        if ($class === '' || ! class_exists($class)) {
            throw new InvalidArgumentException("Adv platform \"{$platform}\" is not installed.");
        }

        return $class;
    }
}
