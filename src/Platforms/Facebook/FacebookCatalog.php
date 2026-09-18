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
 * - /{product_catalog_id}/agencies
 * - /{product_catalog_id}/assigned_users
 * - /{user_id}/assigned_product_catalogs
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

    public const DEFAULT_AGENCY_FIELDS = [
        'id',
        'name',
    ];

    public const DEFAULT_ASSIGNED_USER_FIELDS = [
        'id',
        'name',
        'tasks',
    ];

    /** 目录可分配任务：MANAGE、ADVERTISE、MANAGE_AR、AA_ANALYZE */
    public const VALID_ASSIGNED_USER_TASKS = [
        'MANAGE',
        'ADVERTISE',
        'MANAGE_AR',
        'AA_ANALYZE',
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
     * 获取对目录有访问权限的代理商 / 业务（Agencies）
     * GET /{catalog_id}/agencies
     */
    public function listAgencies(
        string $catalogId,
        array $fields = self::DEFAULT_AGENCY_FIELDS,
        int $limit = 100
    ): array {
        $agencies = $this->client->getAll(
            "/{$catalogId}/agencies",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{catalog_id}/agencies'
        );

        return Arr::uniqueBy($agencies, 'id');
    }

    /**
     * 流式遍历目录 Agencies
     * GET /{catalog_id}/agencies
     */
    public function iterateAgencies(
        string $catalogId,
        array $fields = self::DEFAULT_AGENCY_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$catalogId}/agencies",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{catalog_id}/agencies'
            ),
            'id'
        );
    }

    /**
     * 为目录分配用户权限
     * POST /{catalog_id}/assigned_users
     *
     * @param string $catalogId 目录 ID
     * @param string $userId Business User / System User ID
     * @param array $tasks 任务列表，如 ['MANAGE', 'ADVERTISE']
     * @param string|null $businessId 所属 Business ID（推荐传入）
     */
    public function addAssignedUser(
        string $catalogId,
        string $userId,
        array $tasks = ['MANAGE', 'ADVERTISE'],
        ?string $businessId = null
    ): array {
        $tasks = $this->normalizeAssignedUserTasks($tasks);

        $body = [
            'user' => $userId,
            'tasks' => $tasks,
        ];
        if ($businessId !== null && $businessId !== '') {
            $body['business'] = $businessId;
        }

        return $this->client->post(
            "/{$catalogId}/assigned_users",
            $body,
            [],
            'POST:/{catalog_id}/assigned_users'
        );
    }

    /**
     * 获取目录已分配用户
     * GET /{catalog_id}/assigned_users?business={business_id}
     */
    public function listAssignedUsers(
        string $catalogId,
        string $businessId,
        array $fields = self::DEFAULT_ASSIGNED_USER_FIELDS,
        int $limit = 100
    ): array {
        $users = $this->client->getAll(
            "/{$catalogId}/assigned_users",
            [
                'business' => $businessId,
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{catalog_id}/assigned_users'
        );

        return Arr::uniqueBy($users, 'id');
    }

    /**
     * 流式遍历目录已分配用户
     */
    public function iterateAssignedUsers(
        string $catalogId,
        string $businessId,
        array $fields = self::DEFAULT_ASSIGNED_USER_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$catalogId}/assigned_users",
                [
                    'business' => $businessId,
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{catalog_id}/assigned_users'
            ),
            'id'
        );
    }

    /**
     * 移除目录已分配用户
     * DELETE /{catalog_id}/assigned_users
     */
    public function removeAssignedUser(
        string $catalogId,
        string $userId,
        ?string $businessId = null
    ): array {
        $query = ['user' => $userId];
        if ($businessId !== null && $businessId !== '') {
            $query['business'] = $businessId;
        }

        return $this->client->delete(
            "/{$catalogId}/assigned_users",
            $query,
            'DELETE:/{catalog_id}/assigned_users'
        );
    }

    /**
     * 获取用户已被分配权限的商品目录
     * GET /{user_id}/assigned_product_catalogs
     *
     * @param string $userId Business User / System User / Pending User ID
     */
    public function listAssignedProductCatalogs(
        string $userId,
        array $fields = self::DEFAULT_CATALOG_FIELDS,
        int $limit = 100
    ): array {
        $catalogs = $this->client->getAll(
            "/{$userId}/assigned_product_catalogs",
            [
                'fields' => implode(',', $fields),
                'limit' => $limit,
            ],
            'GET:/{user_id}/assigned_product_catalogs'
        );

        return Arr::uniqueBy($catalogs, 'id');
    }

    /**
     * 流式遍历用户已被分配的商品目录
     * GET /{user_id}/assigned_product_catalogs
     */
    public function iterateAssignedProductCatalogs(
        string $userId,
        array $fields = self::DEFAULT_CATALOG_FIELDS,
        int $limit = 100
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/{$userId}/assigned_product_catalogs",
                [
                    'fields' => implode(',', $fields),
                    'limit' => $limit,
                ],
                'GET:/{user_id}/assigned_product_catalogs'
            ),
            'id'
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

    /**
     * @param list<string> $tasks
     * @return list<string>
     */
    protected function normalizeAssignedUserTasks(array $tasks): array
    {
        $normalized = [];
        foreach ($tasks as $task) {
            $task = strtoupper(trim((string) $task));
            if ($task === '') {
                continue;
            }
            if (! in_array($task, self::VALID_ASSIGNED_USER_TASKS, true)) {
                throw new \InvalidArgumentException(
                    'Invalid catalog task. Must be one of: ' . implode(', ', self::VALID_ASSIGNED_USER_TASKS)
                );
            }
            $normalized[] = $task;
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            throw new \InvalidArgumentException('tasks 不能为空');
        }

        return $normalized;
    }
}
