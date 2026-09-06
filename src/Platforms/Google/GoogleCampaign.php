<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

/**
 * Google Ads 广告系列服务
 */
class GoogleCampaign
{
    public function __construct(
        protected GoogleClient $client
    ) {}

    /**
     * 查询广告系列列表
     */
    public function listCampaigns(string $customerId, int $limit = 1000): array
    {
        $query = 'SELECT campaign.id, campaign.name, campaign.status, campaign.resource_name '
            . 'FROM campaign ORDER BY campaign.id LIMIT ' . max(1, $limit);

        return iterator_to_array($this->client->iterateSearch($customerId, $query));
    }

    /**
     * 流式遍历广告系列
     */
    public function iterateCampaigns(string $customerId): \Generator
    {
        $query = 'SELECT campaign.id, campaign.name, campaign.status, campaign.resource_name FROM campaign';

        yield from $this->client->iterateSearch($customerId, $query);
    }

    /**
     * 修改广告系列状态（ENABLED / PAUSED）
     */
    public function updateCampaignStatus(string $customerId, string $campaignResourceName, string $status): array
    {
        $customerId = GoogleClient::normalizeCustomerId($customerId);

        return $this->client->post(
            "/{$this->client->getApiVersion()}/customers/{$customerId}/campaigns:mutate",
            [
                'operations' => [
                    [
                        'updateMask' => 'status',
                        'update' => [
                            'resourceName' => $campaignResourceName,
                            'status' => strtoupper($status),
                        ],
                    ],
                ],
            ]
        );
    }
}
