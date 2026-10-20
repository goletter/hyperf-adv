<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\Support\Arr;

/**
 * Facebook 像素（Pixel）服务
 *
 * 对应 Graph API：
 * - /{business_id}/owned_pixels
 * - /{business_id}/client_pixels
 * - /{business_id}/pending_shared_offsite_signal_container_business_objects
 * - /{pixel_id}
 * - /{pixel_id}/shared_accounts
 */
class FacebookPixel
{
    public const DEFAULT_PIXEL_FIELDS = [
        'id',
        'name',
    ];

    public const DEFAULT_SHARED_ACCOUNT_FIELDS = [
        'id',
        'name',
        'account_status',
    ];

    public const DEFAULT_PENDING_SHARED_FIELDS = [
        'id',
        'primary_container_id',
        'business',
        'name',
        'is_unavailable',
        'agreement',
    ];

    public function __construct(
        protected FacebookClient $client
    ) {}

    /**
     * 创建像素（Business 拥有）
     * POST /{business_id}/owned_pixels
     */
    public function createPixel(string $businessId, string $name, array $options = []): array
    {
        $body = array_merge(['name' => $name], $options);

        return $this->client->post(
            "/{$businessId}/owned_pixels",
            $body,
            [],
            'POST:/{business_id}/owned_pixels'
        );
    }

    /**
     * 获取像素详情
     */
    public function getPixel(
        string $pixelId,
        array $fields = self::DEFAULT_PIXEL_FIELDS
    ): array {
        return $this->client->get("/{$pixelId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{pixel_id}');
    }

    /**
     * BM 下自有像素列表
     * GET /{business_id}/owned_pixels
     */
    public function listOwnedPixels(
        string $businessId,
        array $fields = self::DEFAULT_PIXEL_FIELDS,
        int $limit = 500
    ): array {
        $pixels = $this->client->getAll(
            "/{$businessId}/owned_pixels",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{business_id}/owned_pixels'
        );

        return Arr::uniqueBy($pixels, 'id');
    }

    /**
     * 流式遍历自有像素
     */
    public function iterateOwnedPixels(
        string $businessId,
        array $fields = self::DEFAULT_PIXEL_FIELDS,
        int $limit = 500
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$businessId}/owned_pixels",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{business_id}/owned_pixels'
            ),
            'id'
        );
    }

    /**
     * BM 下客户端像素列表
     * GET /{business_id}/client_pixels
     */
    public function listClientPixels(
        string $businessId,
        array $fields = self::DEFAULT_PIXEL_FIELDS,
        int $limit = 500
    ): array {
        $pixels = $this->client->getAll(
            "/{$businessId}/client_pixels",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{business_id}/client_pixels'
        );

        return Arr::uniqueBy($pixels, 'id');
    }

    /**
     * 流式遍历客户端像素
     */
    public function iterateClientPixels(
        string $businessId,
        array $fields = self::DEFAULT_PIXEL_FIELDS,
        int $limit = 500
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$businessId}/client_pixels",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{business_id}/client_pixels'
            ),
            'id'
        );
    }

    /**
     * 查询待审批的共享像素
     * GET /{business_id}/pending_shared_offsite_signal_container_business_objects
     * primary_container_id = 像素 ID
     */
    public function listPendingSharedPixels(
        string $businessId,
        array $fields = self::DEFAULT_PENDING_SHARED_FIELDS
    ): array {
        return $this->client->get(
            "/{$businessId}/pending_shared_offsite_signal_container_business_objects",
            [
                'fields' => implode(',', $fields),
            ],
            'GET:/{business_id}/pending_shared_offsite_signal_container_business_objects'
        );
    }

    /**
     * 接收像素：审批资产共享协议
     * POST /{agreement_id}?request_status=APPROVE
     */
    public function approveAssetSharingAgreement(string $agreementId): array
    {
        return $this->client->post(
            "/{$agreementId}",
            ['request_status' => 'APPROVE'],
            [],
            'POST:/{agreement_id}'
        );
    }

    /**
     * 像素共享到广告账户
     * POST /{pixel_id}/shared_accounts
     */
    public function shareToAdAccount(string $pixelId, string $businessId, string $accountId): array
    {
        $accountId = preg_replace('/^act_/i', '', $accountId) ?: $accountId;

        return $this->client->post(
            "/{$pixelId}/shared_accounts",
            [
                'business' => $businessId,
                'account_id' => $accountId,
            ],
            [],
            'POST:/{pixel_id}/shared_accounts'
        );
    }

    /**
     * 取消像素与广告账户的共享
     * DELETE /{pixel_id}/shared_accounts
     */
    public function unshareFromAdAccount(string $pixelId, string $businessId, string $accountId): array
    {
        $accountId = preg_replace('/^act_/i', '', $accountId) ?: $accountId;

        return $this->client->delete(
            "/{$pixelId}/shared_accounts",
            [
                'business' => $businessId,
                'account_id' => $accountId,
            ],
            'DELETE:/{pixel_id}/shared_accounts'
        );
    }

    /**
     * 像素已共享的广告账户列表
     * GET /{pixel_id}/shared_accounts?business={business_id}
     */
    public function listSharedAccounts(
        string $pixelId,
        string $businessId,
        array $fields = self::DEFAULT_SHARED_ACCOUNT_FIELDS,
        int $limit = 500
    ): array {
        $accounts = $this->client->getAll(
            "/{$pixelId}/shared_accounts",
            [
                'business' => $businessId,
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{pixel_id}/shared_accounts'
        );

        return Arr::uniqueBy($accounts, 'id');
    }

    /**
     * 流式遍历像素已共享的广告账户
     */
    public function iterateSharedAccounts(
        string $pixelId,
        string $businessId,
        array $fields = self::DEFAULT_SHARED_ACCOUNT_FIELDS,
        int $limit = 500
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$pixelId}/shared_accounts",
                [
                    'business' => $businessId,
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{pixel_id}/shared_accounts'
            ),
            'id'
        );
    }

    // ---------- 兼容旧方法名（原 FacebookBusiness） ----------

    /** @deprecated 使用 listPendingSharedPixels() */
    public function getPendingSharedPixels(
        string $businessId,
        array $fields = self::DEFAULT_PENDING_SHARED_FIELDS
    ): array {
        return $this->listPendingSharedPixels($businessId, $fields);
    }

    /** @deprecated 使用 shareToAdAccount() */
    public function sharePixelToAdAccount(string $pixelId, string $businessId, string $accountId): array
    {
        return $this->shareToAdAccount($pixelId, $businessId, $accountId);
    }

    /** @deprecated 使用 listSharedAccounts() */
    public function getPixelSharedAccounts(
        string $pixelId,
        string $businessId,
        array $fields = self::DEFAULT_SHARED_ACCOUNT_FIELDS
    ): array {
        return $this->listSharedAccounts($pixelId, $businessId, $fields);
    }

    /** @deprecated 使用 listClientPixels() */
    public function getClientPixels(string $businessId, array $fields = self::DEFAULT_PIXEL_FIELDS): array
    {
        return $this->listClientPixels($businessId, $fields);
    }

    /** @deprecated 使用 listOwnedPixels() */
    public function getOwnedPixels(string $businessId, array $fields = self::DEFAULT_PIXEL_FIELDS): array
    {
        return $this->listOwnedPixels($businessId, $fields);
    }
}
