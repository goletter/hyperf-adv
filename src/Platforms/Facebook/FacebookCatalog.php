<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\Support\Arr;

/**
 * Facebook 商品目录（Product Catalog）服务
 *
 * 对应 Graph API：
 * - /{business_id}/owned_product_catalogs
 * - /{business_id}/client_product_catalogs
 * - /{product_catalog_id}
 * - /{product_catalog_id}/products
 * - /{product_catalog_id}/product_sets
 * - /{product_catalog_id}/product_feeds
 * - /{product_catalog_id}/items_batch
 */
class FacebookCatalog
{
    public const DEFAULT_CATALOG_FIELDS = [
        'id',
        'name',
        'vertical',
        'product_count',
        'business',
    ];

    public const DEFAULT_PRODUCT_FIELDS = [
        'id',
        'retailer_id',
        'name',
        'description',
        'price',
        'currency',
        'availability',
        'image_url',
        'url',
    ];

    public const DEFAULT_PRODUCT_SET_FIELDS = [
        'id',
        'name',
        'filter',
        'product_count',
    ];

    public const DEFAULT_PRODUCT_FEED_FIELDS = [
        'id',
        'name',
        'schedule',
        'product_count',
        'latest_upload',
    ];

    public function __construct(
        protected FacebookClient $client
    ) {}

    /**
     * 创建商品目录（Business 拥有）
     *
     * @param string $businessId Business Manager ID
     * @param string $name 目录名称
     * @param string $vertical 行业，默认 commerce；可选 hotels、flights、destinations、vehicles 等
     * @param array $options 其它创建参数，如 parent_catalog_id、store_catalog_settings 等
     */
    public function createCatalog(
        string $businessId,
        string $name,
        string $vertical = 'commerce',
        array $options = []
    ): array {
        $body = array_merge([
            'name' => $name,
            'vertical' => $vertical,
        ], $options);

        return $this->client->post(
            "/{$businessId}/owned_product_catalogs",
            $body,
            [],
            'POST:/{business_id}/owned_product_catalogs'
        );
    }

    /**
     * 获取 Business 拥有的商品目录
     */
    public function listOwnedCatalogs(
        string $businessId,
        array $fields = self::DEFAULT_CATALOG_FIELDS,
        int $limit = 100
    ): array {
        $catalogs = $this->client->getAll(
            "/{$businessId}/owned_product_catalogs",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{business_id}/owned_product_catalogs'
        );

        return Arr::uniqueBy($catalogs, 'id');
    }

    /**
     * 流式遍历 Business 拥有的商品目录
     */
    public function iterateOwnedCatalogs(
        string $businessId,
        array $fields = self::DEFAULT_CATALOG_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$businessId}/owned_product_catalogs",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{business_id}/owned_product_catalogs'
            ),
            'id'
        );
    }

    /**
     * 获取 Business 下客户共享的商品目录
     */
    public function listClientCatalogs(
        string $businessId,
        array $fields = self::DEFAULT_CATALOG_FIELDS,
        int $limit = 100
    ): array {
        $catalogs = $this->client->getAll(
            "/{$businessId}/client_product_catalogs",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{business_id}/client_product_catalogs'
        );

        return Arr::uniqueBy($catalogs, 'id');
    }

    /**
     * 流式遍历客户共享的商品目录
     */
    public function iterateClientCatalogs(
        string $businessId,
        array $fields = self::DEFAULT_CATALOG_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$businessId}/client_product_catalogs",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{business_id}/client_product_catalogs'
            ),
            'id'
        );
    }

    /**
     * 获取单个商品目录详情
     */
    public function getCatalog(
        string $catalogId,
        array $fields = self::DEFAULT_CATALOG_FIELDS
    ): array {
        return $this->client->get("/{$catalogId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{product_catalog_id}');
    }

    /**
     * 更新商品目录
     */
    public function updateCatalog(string $catalogId, array $data): array
    {
        if ($data === []) {
            throw new \InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        return $this->client->post(
            "/{$catalogId}",
            $data,
            [],
            'POST:/{product_catalog_id}'
        );
    }

    /**
     * 删除商品目录
     */
    public function deleteCatalog(string $catalogId): array
    {
        return $this->client->delete(
            "/{$catalogId}",
            [],
            'DELETE:/{product_catalog_id}'
        );
    }

    /**
     * 获取目录下商品列表
     */
    public function listProducts(
        string $catalogId,
        array $fields = self::DEFAULT_PRODUCT_FIELDS,
        array $filters = [],
        int $limit = 100
    ): array {
        $params = $this->buildListParams($fields, $filters, $limit);
        $products = $this->client->getAll(
            "/{$catalogId}/products",
            $params,
            'GET:/{product_catalog_id}/products'
        );

        return Arr::uniqueBy($products, 'id');
    }

    /**
     * 流式遍历目录商品
     */
    public function iterateProducts(
        string $catalogId,
        array $fields = self::DEFAULT_PRODUCT_FIELDS,
        array $filters = [],
        int $limit = 100
    ): \Generator {
        $params = $this->buildListParams($fields, $filters, $limit);

        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$catalogId}/products",
                $params,
                'GET:/{product_catalog_id}/products'
            ),
            'id'
        );
    }

    /**
     * 向目录添加单个商品
     *
     * @param array $data 商品字段，常见：retailer_id、name、description、url、image_url、price、currency、availability 等
     */
    public function createProduct(string $catalogId, array $data): array
    {
        if ($data === []) {
            throw new \InvalidArgumentException('至少需要提供一个商品字段');
        }

        return $this->client->post(
            "/{$catalogId}/products",
            $data,
            [],
            'POST:/{product_catalog_id}/products'
        );
    }

    /**
     * 更新商品（Product Item）
     */
    public function updateProduct(string $productId, array $data): array
    {
        if ($data === []) {
            throw new \InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        return $this->client->post(
            "/{$productId}",
            $data,
            [],
            'POST:/{product_item_id}'
        );
    }

    /**
     * 删除商品
     */
    public function deleteProduct(string $productId): array
    {
        return $this->client->delete(
            "/{$productId}",
            [],
            'DELETE:/{product_item_id}'
        );
    }

    /**
     * 批量创建 / 更新 / 删除目录商品（Catalog Batch API）
     *
     * @param string $catalogId 目录 ID
     * @param array $requests 请求列表，每项含 method、retailer_id、data 等
     * @param string $itemType 默认 PRODUCT_ITEM
     * @param bool $allowUpsert 是否允许 upsert
     */
    public function itemsBatch(
        string $catalogId,
        array $requests,
        string $itemType = 'PRODUCT_ITEM',
        bool $allowUpsert = true
    ): array {
        if ($requests === []) {
            throw new \InvalidArgumentException('requests 不能为空');
        }

        return $this->client->post(
            "/{$catalogId}/items_batch",
            [
                'item_type' => $itemType,
                'allow_upsert' => $allowUpsert,
                'requests' => $requests,
            ],
            [],
            'POST:/{product_catalog_id}/items_batch'
        );
    }

    /**
     * 获取目录下的商品集
     */
    public function listProductSets(
        string $catalogId,
        array $fields = self::DEFAULT_PRODUCT_SET_FIELDS,
        int $limit = 100
    ): array {
        $sets = $this->client->getAll(
            "/{$catalogId}/product_sets",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{product_catalog_id}/product_sets'
        );

        return Arr::uniqueBy($sets, 'id');
    }

    /**
     * 流式遍历商品集
     */
    public function iterateProductSets(
        string $catalogId,
        array $fields = self::DEFAULT_PRODUCT_SET_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$catalogId}/product_sets",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{product_catalog_id}/product_sets'
            ),
            'id'
        );
    }

    /**
     * 创建商品集
     *
     * @param array|string|null $filter 过滤规则；数组会自动 json_encode
     */
    public function createProductSet(
        string $catalogId,
        string $name,
        array|string|null $filter = null,
        array $options = []
    ): array {
        $body = array_merge(['name' => $name], $options);
        if ($filter !== null) {
            $body['filter'] = is_array($filter) ? json_encode($filter) : $filter;
        }

        return $this->client->post(
            "/{$catalogId}/product_sets",
            $body,
            [],
            'POST:/{product_catalog_id}/product_sets'
        );
    }

    /**
     * 更新商品集
     */
    public function updateProductSet(string $productSetId, array $data): array
    {
        if ($data === []) {
            throw new \InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        if (isset($data['filter']) && is_array($data['filter'])) {
            $data['filter'] = json_encode($data['filter']);
        }

        return $this->client->post(
            "/{$productSetId}",
            $data,
            [],
            'POST:/{product_set_id}'
        );
    }

    /**
     * 删除商品集
     */
    public function deleteProductSet(string $productSetId): array
    {
        return $this->client->delete(
            "/{$productSetId}",
            [],
            'DELETE:/{product_set_id}'
        );
    }

    /**
     * 获取目录下的商品 Feed
     */
    public function listProductFeeds(
        string $catalogId,
        array $fields = self::DEFAULT_PRODUCT_FEED_FIELDS,
        int $limit = 100
    ): array {
        $feeds = $this->client->getAll(
            "/{$catalogId}/product_feeds",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{product_catalog_id}/product_feeds'
        );

        return Arr::uniqueBy($feeds, 'id');
    }

    /**
     * 流式遍历商品 Feed
     */
    public function iterateProductFeeds(
        string $catalogId,
        array $fields = self::DEFAULT_PRODUCT_FEED_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$catalogId}/product_feeds",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{product_catalog_id}/product_feeds'
            ),
            'id'
        );
    }

    /**
     * 创建商品 Feed
     *
     * @param array $options 其它参数，如 schedule、file_name、update_schedule 等
     */
    public function createProductFeed(
        string $catalogId,
        string $name,
        array $options = []
    ): array {
        $body = array_merge(['name' => $name], $options);

        return $this->client->post(
            "/{$catalogId}/product_feeds",
            $body,
            [],
            'POST:/{product_catalog_id}/product_feeds'
        );
    }

    /**
     * 删除商品 Feed
     */
    public function deleteProductFeed(string $feedId): array
    {
        return $this->client->delete(
            "/{$feedId}",
            [],
            'DELETE:/{product_feed_id}'
        );
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
