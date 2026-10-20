<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

use Goletter\Adv\AdvFactory;

/**
 * 把 TikTok 注册到 AdvFactory。
 */
class TikTokPlatform
{
    public static function register(): void
    {
        AdvFactory::register(AdvFactory::TIKTOK, [self::class, 'create']);
    }

    /**
     * @param string|list<string> $accessToken
     * @param array<string, mixed> $options
     */
    public static function create(string|array $accessToken, array $options): TikTokBundle
    {
        $client = new TikTokClient(
            $accessToken,
            (string) ($options['base_uri'] ?? 'https://business-api.tiktok.com')
        );

        if (! empty($options['default_headers']) && is_array($options['default_headers'])) {
            $client->setDefaultHeaders($options['default_headers']);
        }

        return new TikTokBundle(
            client: $client,
            account: new TikTokAccount($client),
            business: new TikTokBusiness($client),
            campaign: new TikTokCampaign($client),
            report: new TikTokReport($client),
            platform: AdvFactory::TIKTOK,
        );
    }

    /**
     * @param array{base_uri?: string, auth_base_uri?: string} $options
     */
    public static function auth(array $options = []): TikTokAuth
    {
        $baseUri = (string) ($options['base_uri'] ?? 'https://business-api.tiktok.com');

        return new TikTokAuth(
            $baseUri,
            (string) ($options['auth_base_uri'] ?? $baseUri)
        );
    }
}
