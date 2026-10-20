<?php

declare(strict_types=1);

namespace Goletter\Adv;

/**
 * 平台服务集合，便于一次拿到 Client + 业务封装。
 *
 * 具体类型由平台实现决定（本包内置 Facebook / TikTok / Google）。
 * 属性上的 @var 用于把泛型交给 IDE；不要只依赖 @property-read，否则会落到原生 object，无法跳转。
 *
 * @template TClient of object
 * @template TAccount of object
 * @template TBusiness of object
 * @template TCampaign of object
 * @template TReport of object
 * @template TCatalog of object|null
 * @template TPixel of object|null
 */
class PlatformBundle
{
    /**
     * @param TClient $client
     * @param TAccount $account
     * @param TBusiness $business
     * @param TCampaign $campaign
     * @param TReport $report
     * @param TCatalog $catalog
     * @param TPixel $pixel
     */
    public function __construct(
        /** @var TClient */
        public readonly object $client,
        /** @var TAccount */
        public readonly object $account,
        /** @var TBusiness */
        public readonly object $business,
        /** @var TCampaign */
        public readonly object $campaign,
        /** @var TReport */
        public readonly object $report,
        public readonly string $platform,
        /** @var TCatalog */
        public readonly ?object $catalog = null,
        /** @var TPixel */
        public readonly ?object $pixel = null,
    ) {}
}
