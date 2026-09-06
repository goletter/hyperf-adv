<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google;

/**
 * Google Ads 账户（Customer）服务
 */
class GoogleAccount
{
    public function __construct(
        protected GoogleClient $client
    ) {}

    /**
     * 当前凭证可访问的客户 ID 列表
     *
     * @return string[] 如 ["customers/1234567890", ...]
     */
    public function listAccessibleCustomers(): array
    {
        $response = $this->client->get("/{$this->client->getApiVersion()}/customers:listAccessibleCustomers");

        return $response['resourceNames'] ?? [];
    }

    /**
     * 流式遍历可访问客户 ID（仅数字部分）
     */
    public function iterateAccessibleCustomerIds(): \Generator
    {
        foreach ($this->listAccessibleCustomers() as $resourceName) {
            yield GoogleClient::normalizeCustomerId($resourceName);
        }
    }

    /**
     * 获取客户详情
     */
    public function getCustomer(string $customerId): array
    {
        $customerId = GoogleClient::normalizeCustomerId($customerId);

        return $this->client->get("/{$this->client->getApiVersion()}/customers/{$customerId}");
    }

    /**
     * GAQL 查询（返回全部行，大数据量请用 iterateSearch）
     */
    public function searchAll(string $customerId, string $query, int $pageSize = 10000): array
    {
        return iterator_to_array(
            $this->client->iterateSearch($customerId, $query, $pageSize)
        );
    }

    /**
     * 更新客户描述性名称（descriptive_name）
     */
    public function updateCustomerName(string $customerId, string $name): array
    {
        $customerId = GoogleClient::normalizeCustomerId($customerId);

        return $this->client->patch(
            "/{$this->client->getApiVersion()}/customers/{$customerId}",
            [
                'descriptiveName' => $name,
                'updateMask' => 'descriptive_name',
            ]
        );
    }

    /**
     * 通过 GAQL 获取账户级指标（余额相关字段因账户类型而异，可按需扩展 query）
     */
    public function getCustomerMetrics(string $customerId, string $date): array
    {
        $query = sprintf(
            "SELECT customer.id, customer.descriptive_name, customer.currency_code, "
            . "metrics.cost_micros, metrics.impressions, metrics.clicks "
            . "FROM customer WHERE segments.date = '%s'",
            $date
        );

        $rows = $this->client->iterateSearch($customerId, $query);

        foreach ($rows as $row) {
            return $row;
        }

        return [];
    }
}
