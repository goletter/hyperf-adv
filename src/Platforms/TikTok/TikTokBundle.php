<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

/**
 * TikTok 平台入口。属性使用具体类型，便于 IDE 跳转。
 */
class TikTokBundle
{
    public function __construct(
        public readonly TikTokClient $client,
        public readonly TikTokAccount $account,
        public readonly TikTokBusiness $business,
        public readonly TikTokCampaign $campaign,
        public readonly TikTokReport $report,
        public readonly string $platform,
        public readonly ?object $catalog = null,
        public readonly ?object $pixel = null,
    ) {}
}
