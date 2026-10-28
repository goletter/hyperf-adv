<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\AdvFactory;

/**
 * 把 Facebook 注册到 AdvFactory。
 */
class FacebookPlatform
{
    public static function register(): void
    {
        AdvFactory::register(AdvFactory::FACEBOOK, [self::class, 'create']);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public static function create(string|array $accessToken, array $options): FacebookBundle
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

        return new FacebookBundle(
            client: $client,
            account: new FacebookAccount($client),
            business: new FacebookBusiness($client),
            campaign: new FacebookCampaign($client),
            ad: new FacebookAd($client),
            report: new FacebookReport($client),
            platform: AdvFactory::FACEBOOK,
            catalog: new FacebookCatalog($client),
            pixel: new FacebookPixel($client),
        );
    }

    /**
     * @param array{api_version?: string} $options
     */
    public static function auth(array $options = []): FacebookAuth
    {
        return new FacebookAuth((string) ($options['api_version'] ?? 'v24.0'));
    }
}
