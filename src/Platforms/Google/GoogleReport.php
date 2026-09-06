<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

/**
 * Google Ads 报表（GAQL）服务
 */
class GoogleReport
{
    public function __construct(
        protected GoogleClient $client
    ) {}

    /**
     * 按日流式拉取账户消耗报表
     *
     * @param string $customerId 客户 ID（可带 customers/ 前缀或连字符）
     * @param string $start 起始日期 Y-m-d
     * @param string $end 结束日期 Y-m-d
     */
    public function iterateDailyReport(
        string $customerId,
        string $start,
        string $end,
        array $extraFields = []
    ): \Generator {
        $fields = array_merge(
            [
                'segments.date',
                'metrics.cost_micros',
                'metrics.impressions',
                'metrics.clicks',
            ],
            $extraFields
        );
        $select = implode(', ', array_unique($fields));
        $query = sprintf(
            '%s FROM customer WHERE segments.date BETWEEN \'%s\' AND \'%s\'',
            'SELECT ' . $select,
            $start,
            $end
        );

        yield from $this->client->iterateSearch($customerId, $query);
    }

    /**
     * 一次性获取日报表
     */
    public function getAllDailyReport(
        string $customerId,
        string $start,
        string $end,
        array $extraFields = []
    ): array {
        return iterator_to_array(
            $this->iterateDailyReport($customerId, $start, $end, $extraFields)
        );
    }

    /**
     * 自定义 GAQL 报表迭代
     */
    public function iterateReport(string $customerId, string $query): \Generator
    {
        yield from $this->client->iterateSearch($customerId, $query);
    }
}
