<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\Support\Arr;

class FacebookCampaign
{
    public const DEFAULT_CAMPAIGN_FIELDS = [
        'id',
        'name',
        'objective',
        'status',
        'effective_status',
        'daily_budget',
        'lifetime_budget',
        'budget_remaining',
        'created_time',
        'updated_time',
    ];

    public function __construct(
        protected FacebookClient $client
    ) {}

    /**
     * 获取广告账户下的所有广告系列
     */
    public function listCampaigns(
        string $accountId,
        array $fields = self::DEFAULT_CAMPAIGN_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): array {
        $accountId = str_replace('act_', '', $accountId);
        $params = $this->buildListParams($fields, $filters, $limit);
        $campaigns = $this->client->getAll(
            "/act_{$accountId}/campaigns",
            $params,
            'GET:/act_{ad_account_id}/campaigns'
        );

        return Arr::uniqueBy($campaigns, 'id');
    }

    /**
     * 流式处理广告系列（推荐大数据量）
     * 自动去重，基于广告系列 ID
     */
    public function iterateCampaigns(
        string $accountId,
        array $fields = self::DEFAULT_CAMPAIGN_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): \Generator {
        $accountId = str_replace('act_', '', $accountId);
        $params = $this->buildListParams($fields, $filters, $limit);

        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/act_{$accountId}/campaigns",
                $params,
                'GET:/act_{ad_account_id}/campaigns'
            ),
            'id'
        );
    }

    /**
     * 获取单个广告系列详情
     */
    public function getCampaign(
        string $campaignId,
        array $fields = [
            'id',
            'name',
            'objective',
            'status',
            'effective_status',
            'daily_budget',
            'lifetime_budget',
            'budget_remaining',
            'created_time',
            'updated_time',
            'start_time',
            'stop_time',
        ]
    ): array {
        return $this->client->get("/{$campaignId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{campaign_id}');
    }

    /**
     * 创建广告系列
     */
    public function createCampaign(
        string $accountId,
        string $name,
        string $objective,
        string $status = 'PAUSED',
        array $options = []
    ): array {
        $accountId = str_replace('act_', '', $accountId);

        $body = array_merge([
            'name' => $name,
            'objective' => $objective,
            'status' => $status,
        ], $options);

        return $this->client->post(
            "/act_{$accountId}/campaigns",
            $body,
            [],
            'POST:/act_{ad_account_id}/campaigns'
        );
    }

    /**
     * 更新广告系列
     */
    public function updateCampaign(string $campaignId, array $data): array
    {
        if ($data === []) {
            throw new \InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        return $this->client->post("/{$campaignId}", $data, [], 'POST:/{campaign_id}');
    }

    /**
     * 更新广告系列状态
     */
    public function updateCampaignStatus(string $campaignId, string $status): array
    {
        $validStatuses = ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'];
        if (! in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                'Invalid status. Must be one of: ' . implode(', ', $validStatuses)
            );
        }

        return $this->client->post("/{$campaignId}", [
            'status' => $status,
        ], [], 'POST:/{campaign_id}');
    }

    public function pauseCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'PAUSED');
    }

    public function activateCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'ACTIVE');
    }

    public function deleteCampaign(string $campaignId): array
    {
        return $this->client->delete("/{$campaignId}", [
            'status' => 'DELETED',
        ], 'DELETE:/{campaign_id}');
    }

    public function archiveCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'ARCHIVED');
    }

    /**
     * 批量更新广告系列状态
     */
    public function batchUpdateStatus(array $campaignIds, string $status): array
    {
        $results = [];

        foreach ($campaignIds as $campaignId) {
            try {
                $results[$campaignId] = [
                    'success' => true,
                    'data' => $this->updateCampaignStatus($campaignId, $status),
                ];
            } catch (\Throwable $e) {
                $results[$campaignId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * 组装列表查询参数（含 Facebook filtering）
     */
    protected function buildListParams(array $fields, array $filters, int $limit): array
    {
        $params = [
            'fields' => implode(',', $fields),
            'limit' => $limit,
        ];

        if ($filters === []) {
            return $params;
        }

        $filtering = [];
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $filtering[] = [
                    'field' => $key,
                    'operator' => 'IN',
                    'value' => $value,
                ];
            } else {
                $params[$key] = $value;
            }
        }

        if ($filtering !== []) {
            $params['filtering'] = json_encode($filtering);
        }

        return $params;
    }
}
