<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

/**
 * TikTok 账户服务
 */
class TikTokAccount
{
    public function __construct(
        protected TikTokClient $client
    ) {}

    /**
     * 获取广告主信息
     */
    public function getAdvertiser(string $advertiserId): array
    {
        $response = $this->client->get('/open_api/v1.3/advertiser/info/', [
            'advertiser_ids' => json_encode([$advertiserId]),
        ]);

        return $response['data']['list'][0] ?? [];
    }

    /**
     * 批量获取广告主信息
     */
    public function listAdvertisers(array $advertiserIds): array
    {
        if ($advertiserIds === []) {
            return [];
        }

        $response = $this->client->get('/open_api/v1.3/advertiser/info/', [
            'advertiser_ids' => json_encode(array_values($advertiserIds)),
        ]);

        return $response['data']['list'] ?? [];
    }

    /**
     * 获取广告系列列表
     */
    public function getCampaign(string $advertiserId): array
    {
        return $this->client->get('/open_api/v1.3/campaign/get/', [
            'advertiser_id' => $advertiserId,
        ]);
    }

    /**
     * 修改广告系列状态
     */
    public function updateCampaignStatus(array $params): array
    {
        return $this->client->post('/open_api/v1.3/campaign/status/update/', $params);
    }

    /**
     * 更新广告主名称
     */
    public function updateAccountName(string $accountId, string $name): array
    {
        return $this->client->post('/open_api/v1.3/advertiser/update/', [
            'advertiser_id' => $accountId,
            'name' => $name,
        ]);
    }

    public function getTransaction(array $params): array
    {
        return $this->client->get('/open_api/v1.3/bc/get/', $params);
    }

    public function getAdvertiserBalance(array $params): array
    {
        return $this->client->get('/open_api/v1.3/advertiser/balance/get/', $params);
    }
}
