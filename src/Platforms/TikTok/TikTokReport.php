<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

/**
 * TikTok 报表服务
 */
class TikTokReport
{
    public function __construct(
        protected TikTokClient $client
    ) {}

    /**
     * 通用报表迭代器（流式处理）
     *
     * @param array $dimensions 维度，例如 ['stat_time_day', 'campaign_id']
     * @param array $metrics 指标，例如 ['spend', 'impressions', 'clicks']
     * @return \Generator<int, array>
     */
    public function iterateReport(
        string $advertiserId,
        string $start,
        string $end,
        array $dimensions = ['stat_time_day'],
        array $metrics = ['spend', 'impressions', 'clicks'],
        int $pageSize = 100,
        int $max = 100000
    ): \Generator {
        yield from $this->client->paginate(
            fn (int $page, int $size) => $this->client->get('/open_api/v1.3/report/integrated/get/', [
                'advertiser_id' => $advertiserId,
                'report_type' => 'BASIC',
                'data_level' => 'AUCTION_ADVERTISER',
                'dimensions' => json_encode($dimensions),
                'metrics' => json_encode($metrics),
                'start_date' => $start,
                'end_date' => $end,
                'page' => $page,
                'page_size' => $size,
            ]),
            $pageSize,
            $max
        );
    }

    /**
     * 一次性获取所有报表数据（不推荐大数据量）
     */
    public function getAllReport(
        string $advertiserId,
        string $start,
        string $end,
        array $dimensions = ['stat_time_day'],
        array $metrics = ['spend', 'impressions', 'clicks'],
        int $pageSize = 100
    ): array {
        return iterator_to_array(
            $this->iterateReport($advertiserId, $start, $end, $dimensions, $metrics, $pageSize)
        );
    }
}
