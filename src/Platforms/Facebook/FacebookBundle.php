<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

/**
 * Facebook 平台入口。属性使用具体类型，IDE 可从 $adv->account 跳到 FacebookAccount::getAccount()。
 */
class FacebookBundle
{
    public function __construct(
        public readonly FacebookClient $client,
        public readonly FacebookAccount $account,
        public readonly FacebookBusiness $business,
        public readonly FacebookCampaign $campaign,
        public readonly FacebookAd $ad,
        public readonly FacebookReport $report,
        public readonly string $platform,
        public readonly ?FacebookCatalog $catalog = null,
        public readonly ?FacebookPixel $pixel = null,
    ) {}
}
