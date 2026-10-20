<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

/**
 * Google Ads 平台入口。属性使用具体类型，便于 IDE 跳转。
 */
class GoogleBundle
{
    public function __construct(
        public readonly GoogleClient $client,
        public readonly GoogleAccount $account,
        public readonly GoogleBusiness $business,
        public readonly GoogleCampaign $campaign,
        public readonly GoogleReport $report,
        public readonly string $platform,
        public readonly ?object $catalog = null,
        public readonly ?object $pixel = null,
    ) {}
}
