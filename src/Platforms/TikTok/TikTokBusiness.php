<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok;

/**
 * TikTok Business Center 服务封装
 */
class TikTokBusiness
{
    public function __construct(
        protected TikTokClient $client
    ) {}

    /**
     * 获取可见的 Business Center 列表（单页）
     */
    public function listBusinessCenters(int $page = 1, int $pageSize = 50): array
    {
        $response = $this->client->get('/open_api/v1.3/business/get/', [
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        return $response['data']['list'] ?? [];
    }

    /**
     * 流式遍历所有 Business Center（推荐大数据量）
     *
     * @return \Generator<int, array>
     */
    public function iterateBusinessCenters(int $pageSize = 50, int $max = 100000): \Generator
    {
        yield from $this->client->paginate(
            fn (int $page, int $size) => $this->client->get('/open_api/v1.3/business/get/', [
                'page' => $page,
                'page_size' => $size,
            ]),
            $pageSize,
            $max
        );
    }

    /**
     * 获取指定 Business Center 下的广告主列表（单页）
     */
    public function listAdvertisersByBusinessCenter(
        string $businessCenterId,
        int $page = 1,
        int $pageSize = 50
    ): array {
        $response = $this->client->get('/open_api/v1.3/business/advertiser/get/', [
            'business_center_id' => $businessCenterId,
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        return $response['data']['list'] ?? [];
    }

    /**
     * 流式遍历某个 Business Center 下的所有广告主
     *
     * @return \Generator<int, array>
     */
    public function iterateAdvertisersByBusinessCenter(
        string $businessCenterId,
        int $pageSize = 50,
        int $max = 100000
    ): \Generator {
        yield from $this->client->paginate(
            fn (int $page, int $size) => $this->client->get('/open_api/v1.3/business/advertiser/get/', [
                'business_center_id' => $businessCenterId,
                'page' => $page,
                'page_size' => $size,
            ]),
            $pageSize,
            $max
        );
    }

    /**
     * 获取 bc 下的资产（例如 advertiser 资产）
     */
    public function getBcAssets(string $bcId, string $assetType): array
    {
        $response = $this->client->get('/open_api/v1.3/bc/asset/get/', [
            'bc_id' => $bcId,
            'asset_type' => $assetType,
        ]);

        return $response['data']['list'] ?? $response['data'] ?? $response;
    }

    /**
     * 给用户分配 bc 资产（assign）
     *
     * @param array $payload 作为 JSON body 发送的参数
     */
    public function assignBcAsset(array $payload): array
    {
        $response = $this->client->post('/open_api/v1.3/bc/asset/assign/', $payload);

        return $response['data'] ?? $response;
    }

    /**
     * 获得 Bc
     */
    public function getBcs(): array
    {
        $response = $this->client->get('/open_api/v1.3/bc/asset/get/');

        return $response['data'] ?? $response;
    }
}
