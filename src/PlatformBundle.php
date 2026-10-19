<?php

declare(strict_types=1);

namespace Goletter\Adv;

/**
 * 平台服务集合，便于一次拿到 Client + 业务封装。
 *
 * 具体类型由已安装的平台包决定（hyperf-adv-facebook / tiktok / google）。
 *
 * @template TClient of object
 * @template TAccount of object
 * @template TBusiness of object
 * @template TCampaign of object
 * @template TReport of object
 *
 * @property-read TClient $client
 * @property-read TAccount $account
 * @property-read TBusiness $business
 * @property-read TCampaign $campaign
 * @property-read TReport $report
 * @property-read object|null $catalog Facebook 专用；其它平台为 null
 * @property-read object|null $pixel Facebook 专用；其它平台为 null
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
        public readonly object $client,
        public readonly object $account,
        public readonly object $business,
        public readonly object $campaign,
        public readonly object $report,
        public readonly string $platform,
        public readonly ?object $catalog = null,
        public readonly ?object $pixel = null,
    ) {}
}
