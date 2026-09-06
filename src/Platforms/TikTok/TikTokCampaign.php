<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

/**
 * TikTok 广告系列（Campaign）服务
 */
class TikTokCampaign
{
    public function __construct(
        protected TikTokClient $client
    ) {}

    /**
     * 获取广告主下的一页 Campaign 列表
     */
    public function listCampaigns(
        string $advertiserId,
        array $filters = [],
        int $page = 1,
        int $pageSize = 50
    ): array {
        $body = array_merge([
            'advertiser_id' => $advertiserId,
            'page' => $page,
            'page_size' => $pageSize,
        ], $filters);

        $response = $this->client->post('/open_api/v1.3/campaign/get/', $body);

        return $response['data']['list'] ?? [];
    }

    /**
     * 流式遍历广告主下的所有 Campaign（推荐大数据量）
     *
     * @return \Generator<int, array>
     */
    public function iterateCampaigns(
        string $advertiserId,
        array $filters = [],
        int $pageSize = 50,
        int $max = 100000
    ): \Generator {
        yield from $this->client->paginate(
            function (int $page, int $size) use ($advertiserId, $filters) {
                $body = array_merge([
                    'advertiser_id' => $advertiserId,
                    'page' => $page,
                    'page_size' => $size,
                ], $filters);

                return $this->client->post('/open_api/v1.3/campaign/get/', $body);
            },
            $pageSize,
            $max
        );
    }
}
