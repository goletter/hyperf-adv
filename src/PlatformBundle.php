<?php

declare(strict_types=1);

namespace Goletter\Adv;

use Goletter\Adv\Platforms\Facebook\FacebookAccount;
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

/**
 * 平台服务集合，便于一次拿到 Client + 业务封装。
 *
 * @template TClient of FacebookClient|TikTokClient|GoogleClient
 * @template TAccount of FacebookAccount|TikTokAccount|GoogleAccount
 * @template TBusiness of FacebookBusiness|TikTokBusiness|GoogleBusiness
 * @template TCampaign of FacebookCampaign|TikTokCampaign|GoogleCampaign
 * @template TReport of FacebookReport|TikTokReport|GoogleReport
 *
 * @property-read TClient $client
 * @property-read TAccount $account
 * @property-read TBusiness $business
 * @property-read TCampaign $campaign
 * @property-read TReport $report
 */
final class PlatformBundle
{
    /**
     * @param TClient $client
     * @param TAccount $account
     * @param TBusiness $business
     * @param TCampaign $campaign
     * @param TReport $report
     */
    public function __construct(
        public readonly FacebookClient|TikTokClient|GoogleClient $client,
        public readonly FacebookAccount|TikTokAccount|GoogleAccount $account,
        public readonly FacebookBusiness|TikTokBusiness|GoogleBusiness $business,
        public readonly FacebookCampaign|TikTokCampaign|GoogleCampaign $campaign,
        public readonly FacebookReport|TikTokReport|GoogleReport $report,
        public readonly string $platform,
    ) {}
}
