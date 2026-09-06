<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

class FacebookReport
{
    protected const DEFAULT_FIELDS = ['account_name', 'account_id', 'spend', 'date_start', 'date_stop'];
    protected const DEFAULT_DAILY_FIELDS = ['date_start', 'spend', 'impressions', 'clicks', 'date_stop'];
    
    public function __construct(
        protected FacebookClient $client
    ) {}

    /**
     * 获取账户级别的 insights 报告
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $start 开始日期 Y-m-d
     * @param string $end 结束日期 Y-m-d
     * @param array $fields 要获取的字段
     * @param string $level 报告级别: account, campaign, adset, ad
     * @param string|int $timeIncrement 时间粒度: 1 (每日), 'all_days' (总计), 'monthly' (每月)
     * @param int $limit 每页限制
     * @return \Generator
     */
    public function iterateInsights(
        string $accountId,
        string $start,
        string $end,
        array $fields = self::DEFAULT_FIELDS,
        string $level = 'account',
        string|int $timeIncrement = 1,
        int $limit = 100
    ): \Generator {
        yield from $this->client->paginate(
            $this->formatInsightsUri($accountId),
            $this->buildInsightsQuery($start, $end, $fields, $level, $timeIncrement, $limit),
            'GET:/act_{ad_account_id}/insights'
        );
    }

    /**
     * 获取每日报告（按天拆分查询，推荐用于长时间范围）
     */
    public function iterateDailyReport(
        string $accountId,
        string $start,
        string $end,
        array $fields = self::DEFAULT_DAILY_FIELDS
    ): \Generator {
        foreach ($this->splitByDay($start, $end) as [$since, $until]) {
            yield from $this->client->paginate(
                $this->formatInsightsUri($accountId),
                $this->buildInsightsQuery($since, $until, $fields, 'account', 1, 100),
                'GET:/act_{ad_account_id}/insights'
            );
        }
    }

    /**
     * 一次性获取所有 insights（不推荐大数据量）
     */
    public function getAllInsights(
        string $accountId,
        string $start,
        string $end,
        array $fields = self::DEFAULT_FIELDS,
        string $level = 'account',
        string|int $timeIncrement = 1,
        int $limit = 100
    ): array {
        return iterator_to_array(
            $this->iterateInsights($accountId, $start, $end, $fields, $level, $timeIncrement, $limit)
        );
    }

    /**
     * 分页获取消耗数据（使用 Cursor 分页，推荐）
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $start 开始日期 Y-m-d
     * @param string $end 结束日期 Y-m-d
     * @param string|null $after Cursor 分页的 after 参数（下一页的 cursor）
     * @param string|null $before Cursor 分页的 before 参数（上一页的 cursor）
     * @param int $limit 每页数量（最大 1000，建议 100）
     * @param array $fields 要获取的字段
     * @param string $level 报告级别: account, campaign, adset, ad
     * @param string|int $timeIncrement 时间粒度: 1 (每日), 'all_days' (总计), 'monthly' (每月)
     * @return array 包含 data, paging 的分页数据
     */
    public function paginateInsights(
        string $accountId,
        string $start,
        string $end,
        ?string $after = null,
        ?string $before = null,
        int $limit = 100,
        array $fields = self::DEFAULT_FIELDS,
        string $level = 'account',
        string|int $timeIncrement = 1
    ): array {
        $query = $this->buildInsightsQuery($start, $end, $fields, $level, $timeIncrement, $limit);
        
        if ($after) {
            $query['after'] = $after;
        }
        if ($before) {
            $query['before'] = $before;
        }

        $response = $this->client->get($this->formatInsightsUri($accountId), $query, 'GET:/act_{ad_account_id}/insights');

        return [
            'data' => $response['data'] ?? [],
            'paging' => $response['paging'] ?? [],
        ];
    }


    /**
     * 格式化账户 ID 为 insights URI
     */
    protected function formatInsightsUri(string $accountId): string
    {
        $id = str_replace('act_', '', $accountId);
        return "/act_{$id}/insights";
    }

    /**
     * 构建 insights 查询参数
     */
    protected function buildInsightsQuery(
        string $start,
        string $end,
        array $fields,
        string $level,
        string|int $timeIncrement,
        int $limit
    ): array {
        return [
            'fields' => implode(',', $fields),
            'level' => $level,
            'time_range' => json_encode(['since' => $start, 'until' => $end]),
            'time_increment' => (string) $timeIncrement,
            'limit' => max(1, min(1000, $limit)),
        ];
    }

    /**
     * 按天拆分日期范围
     */
    protected function splitByDay(string $start, string $end): array
    {
        $ranges = [];
        $cur = new \DateTime($start);
        $endAt = new \DateTime($end);

        while ($cur <= $endAt) {
            $day = $cur->format('Y-m-d');
            $ranges[] = [$day, $day];
            $cur->modify('+1 day');
        }

        return $ranges;
    }
}