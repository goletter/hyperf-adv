<?php

declare(strict_types=1);

namespace Goletter\Adv;

/**
 * 平台服务集合，便于一次拿到 Client + 业务封装。
 */
final class PlatformBundle
{
    public function __construct(
        public readonly object $client,
        public readonly object $account,
        public readonly object $business,
        public readonly object $campaign,
        public readonly object $report,
        public readonly string $platform,
    ) {}
}
