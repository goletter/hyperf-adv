<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\Support\Arr;

class FacebookAccount
{
    protected const VALID_ROLES = ['ADMIN', 'ADVERTISER', 'ANALYST'];

    public const DEFAULT_ACCOUNT_FIELDS = [
        'id',
        'name',
        'account_status',
        'spend_cap',
        'amount_spent',
        'currency',
        'created_time',
        'timezone_offset_hours_utc',
        'business_country_code',
    ];

    /**
     * 默认公司地址，可通过 setDefaultBusinessInfo() 覆盖，避免写死多环境地址。
     */
    protected static ?array $defaultBusinessInfo = null;

    public function __construct(
        protected FacebookClient $client
    ) {}

    /**
     * 注册默认 business_info（建议在应用启动时配置一次）。
     */
    public static function setDefaultBusinessInfo(?array $businessInfo): void
    {
        self::$defaultBusinessInfo = $businessInfo;
    }

    /**
     * 获取当前用户的所有广告账户
     */
    public function listAccounts(array $fields = ['id', 'name', 'currency', 'account_status']): array
    {
        return $this->client->getAll('/me/adaccounts', [
            'fields' => implode(',', $fields),
        ], 'GET:/me/adaccounts');
    }

    /**
     * 流式处理用户账户（推荐）
     */
    public function iterateAccounts(array $fields = ['id', 'name', 'currency']): \Generator
    {
        return $this->client->paginate('/me/adaccounts', [
            'fields' => implode(',', $fields),
        ], 'GET:/me/adaccounts');
    }

    /**
     * 获取 Business Manager 下的客户广告账户（client_ad_accounts）
     * 
     * @param string $businessId Business Manager ID (不需要 act_ 前缀)
     * @param array $fields 要获取的字段列表
     * @param int $limit 每页限制
     * @return array 已去重的账户列表
     */
    public function listBusinessAccounts(
        string $businessId,
        array $fields = self::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): array {
        return $this->listBusinessAccountsByType($businessId, 'client_ad_accounts', $fields, $limit);
    }

    /**
     * 流式处理 BM 客户账户（推荐大数据量）
     * 自动去重，基于账户 ID
     */
    public function iterateBusinessAccounts(
        string $businessId,
        array $fields = self::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): \Generator {
        yield from $this->iterateBusinessAccountsByType($businessId, 'client_ad_accounts', $fields, $limit);
    }

    /**
     * 获取 Business Manager 拥有的广告账户（owned_ad_accounts）
     * 
     * @param string $businessId Business Manager ID (不需要 act_ 前缀)
     * @param array $fields 要获取的字段列表
     * @param int $limit 每页限制
     * @return array 已去重的账户列表
     */
    public function listOwnedBusinessAccounts(
        string $businessId,
        array $fields = self::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): array {
        return $this->listBusinessAccountsByType($businessId, 'owned_ad_accounts', $fields, $limit);
    }

    /**
     * 流式处理 BM 拥有的账户（推荐大数据量）
     * 自动去重，基于账户 ID
     */
    public function iterateOwnedBusinessAccounts(
        string $businessId,
        array $fields = self::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): \Generator {
        yield from $this->iterateBusinessAccountsByType($businessId, 'owned_ad_accounts', $fields, $limit);
    }

    /**
     * 获取单个账户详情
     */
    public function getAccount(string $accountId, array $fields = ['id', 'name', 'spend_cap', 'amount_spent', 'balance', 'currency', 'account_status', 'timezone_offset_hours_utc']): array
    {
        return $this->client->get($this->formatAccountUri($accountId), [
            'fields' => $this->formatFields($fields),
        ], 'GET:/act_{ad_account_id}');
    }

    /**
     * 获取账户活动（ad account activities）
     *
     * 常见场景：拉取 ad_account_billing_charge 计费活动。
     *
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $since 开始时间（Y-m-d）
     * @param string $until 结束时间（Y-m-d）
     * @param array $fields 返回字段
     * @param string $eventType 事件类型
     * @param int $limit 每页大小
     * @return array
     */
    public function listActivities(
        string $accountId,
        string $since,
        string $until,
        array $fields = ['event_time', 'event_type', 'extra_data'],
        string $eventType = 'ad_account_billing_charge',
        int $limit = 1000
    ): array {
        return $this->client->getAll("{$this->formatAccountUri($accountId)}/activities", [
            'fields' => $this->formatFields($fields),
            'event_type' => $eventType,
            'since' => $since,
            'until' => $until,
            'limit' => $limit,
        ], 'GET:/act_{ad_account_id}/activities');
    }

    /**
     * 流式获取账户活动（推荐）
     *
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $since 开始时间（Y-m-d）
     * @param string $until 结束时间（Y-m-d）
     * @param array $fields 返回字段
     * @param string $eventType 事件类型
     * @param int $limit 每页大小
     */
    public function iterateActivities(
        string $accountId,
        string $since,
        string $until,
        array $fields = ['event_time', 'event_type', 'extra_data'],
        string $eventType = 'ad_account_billing_charge',
        int $limit = 1000
    ): \Generator {
        yield from $this->client->paginate("{$this->formatAccountUri($accountId)}/activities", [
            'fields' => $this->formatFields($fields),
            'event_type' => $eventType,
            'since' => $since,
            'until' => $until,
            'limit' => $limit,
        ], 'GET:/act_{ad_account_id}/activities');
    }

    /**
     * 更新广告账户名称
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $name 新的账户名称
     * @return array 更新后的账户信息
     */
    public function updateAccountName(string $accountId, string $name): array
    {
        return $this->updateAccount($accountId, ['name' => $name]);
    }

    /**
     * 更新广告账户支出限额（spend_cap）
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string|null $spendCap 新的支出限额，以分为单位（例如：100000 = 1000美元）。传入 null 表示移除限额
     * @return array 更新后的账户信息
     */
    public function updateAccountSpendCap(string $accountId, ?string $spendCap = null): array
    {
        return $this->updateAccount($accountId, [
            'spend_cap' => $spendCap === null ? '' : $spendCap
        ]);
    }


    /**
     * 更新广告账户的货币
     *
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string|null
     * @return array 更新后的账户信息
     */
    public function updateAccountCurrency(string $accountId, string $currency): array
    {
        return $this->updateAccount($accountId, [
            'currency' => $currency,
        ]);
    }

    /**
     * 更新广告账户的时区
     *
     * @param array|null $businessInfo 为空时使用默认 business_info
     */
    public function updateAccountTimezone(string $accountId, int $timezoneId, ?array $businessInfo = null): array
    {
        return $this->updateAccount($accountId, [
            'timezone_id' => $timezoneId,
            'business_info' => $businessInfo ?? $this->resolveBusinessInfo(),
        ]);
    }

    /**
     * 更新广告账户公司信息
     *
     * @param array|null $businessInfo 为空时使用默认 business_info
     */
    public function updateAccountBusinessInfo(string $accountId, ?array $businessInfo = null): array
    {
        return $this->updateAccount($accountId, [
            'business_info' => $businessInfo ?? $this->resolveBusinessInfo(),
        ]);
    }

    /**
     * 删除广告账户限额（spend_cap_action）
     *
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param int|null $SpendCapAction delete
     * @return array 更新后的账户信息
     */
    public function deleteAccountSpendCapAction(string $accountId, ?string $SpendCapAction = null): array
    {
        return $this->updateAccount($accountId, [
            'spend_cap_action' => $SpendCapAction === null ? '' : $SpendCapAction
        ]);
    }

    /**
     * 更新广告账户
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param array $data 要更新的数据，例如：['name' => '新名称', 'spend_cap' => 100000]
     * @return array 更新后的账户信息
     */
    public function updateAccount(string $accountId, array $data): array
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('至少需要提供一个要更新的字段');
        }
        
        return $this->client->post($this->formatAccountUri($accountId), $data, [], 'POST:/act_{ad_account_id}');
    }

    /**
     * 删除广告账户（从 Business Manager 移除）
     * 注意：此操作会从 Business Manager 中移除广告账户的访问权限，并不会真正删除账户
     * 
     * @param string $businessId Business Manager ID
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @return array API 响应
     */
    public function removeAccountFromBusiness(string $businessId, string $accountId): array
    {
        return $this->client->delete("/{$businessId}/ad_accounts", [
            'adaccount_id' => $this->formatAccountId($accountId, true),
        ], 'DELETE:/{business_id}/ad_accounts');
    }

    /**
     * 获取广告账户的分配用户列表
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param array $fields 要获取的字段列表
     * @return array
     */
    public function listAssignedUsers(string $accountId, array $fields = ['id', 'name', 'email', 'role']): array
    {
        return $this->client->getAll("{$this->formatAccountUri($accountId)}/assigned_users", [
            'fields' => $this->formatFields($fields),
        ], 'GET:/act_{ad_account_id}/assigned_users');
    }

    /**
     * 流式处理广告账户的分配用户
     */
    public function iterateAssignedUsers(string $accountId, array $fields = ['id', 'name', 'email', 'role']): \Generator
    {
        yield from $this->client->paginate("{$this->formatAccountUri($accountId)}/assigned_users", [
            'fields' => $this->formatFields($fields),
        ], 'GET:/act_{ad_account_id}/assigned_users');
    }

    /**
     * 添加用户到广告账户
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $userId 用户ID（Facebook User ID）
     * @param string $role 用户角色：ADMIN, ADVERTISER, ANALYST
     * @return array API 响应
     */
    public function addAssignedUser(string $accountId, string $userId, string $role = 'ADMIN'): array
    {
        $this->validateRole($role);
        $tasks = ['ADVERTISE'];
        if($role == 'ADMIN'){
            $tasks = ['MANAGE','ADVERTISE','ANALYZE'];
        }
        
        return $this->client->post("{$this->formatAccountUri($accountId)}/assigned_users", [
            'user' => $userId,
            // 'role' => $role,
            'tasks'=> $tasks,
        ], [], 'POST:/act_{ad_account_id}/assigned_users');
    }

    /**
     * 移除广告账户的用户
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $userId 用户ID（Facebook User ID）
     * @return array API 响应
     */
    public function removeAssignedUser(string $accountId, string $userId): array
    {
        return $this->client->delete("{$this->formatAccountUri($accountId)}/assigned_users", [
            'user' => $userId,
        ], 'DELETE:/act_{ad_account_id}/assigned_users');
    }

    /**
     * 获取Business Manager 下的业务用户
     * 
     * @param string $bmid 账户ID（Business Manager ID）
     */
    public function getBusinessUsers(string $bmid)
    {
        return $this->client->get("/{$bmid}/business_users",[            
            'limit' => 1500
        ], 'GET:/{business_id}/business_users');
    }

    /**
     * 更新广告账户用户的角色
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param string $userId 用户ID（Facebook User ID）
     * @param string $role 新的用户角色：ADMIN, ADVERTISER, ANALYST
     * @return array API 响应
     */
    public function updateAssignedUserRole(string $accountId, string $userId, string $role): array
    {
        $this->validateRole($role);
        
        return $this->client->post("{$this->formatAccountUri($accountId)}/assigned_users", [
            'user' => $userId,
            'role' => $role,
        ], [], 'POST:/act_{ad_account_id}/assigned_users');
    }

    /**
     * 获取广告账户下的广告系列（快捷方法）
     * 
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @param array $fields 要获取的字段列表
     * @param array $filters 过滤条件
     * @param int $limit 每页限制
     * @return array
     */
    public function listCampaigns(
        string $accountId,
        array $fields = ['id', 'name', 'objective', 'status', 'effective_status'],
        array $filters = [],
        int $limit = 1000
    ): array {
        $campaign = new FacebookCampaign($this->client);
        return $campaign->listCampaigns($accountId, $fields, $filters, $limit);
    }

    /**
     * 流式处理广告账户下的广告系列（快捷方法）
     */
    public function iterateCampaigns(
        string $accountId,
        array $fields = ['id', 'name', 'objective', 'status', 'effective_status'],
        array $filters = [],
        int $limit = 1000
    ): \Generator {
        $campaign = new FacebookCampaign($this->client);
        yield from $campaign->iterateCampaigns($accountId, $fields, $filters, $limit);
    }

    /**
     * 格式化账户 URI
     */
    protected function formatAccountUri(string $accountId): string
    {
        return '/act_' . $this->formatAccountId($accountId);
    }

    /**
     * 格式化账户 ID（移除 act_ 前缀）
     */
    protected function formatAccountId(string $accountId, bool $withPrefix = false): string
    {
        $id = str_replace('act_', '', $accountId);
        return $withPrefix ? "act_{$id}" : $id;
    }

    /**
     * 格式化字段数组为字符串
     */
    protected function formatFields(array $fields): string
    {
        return implode(',', $fields);
    }

    /**
     * 验证角色是否有效
     */
    protected function validateRole(string $role): void
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid role "%s". Must be one of: %s', $role, implode(', ', self::VALID_ROLES))
            );
        }
    }

    /**
     * 基于指定字段去重数组（供内部和外部使用）
     */
    public function deduplicateByField(array $items, string $field = 'id'): array
    {
        return Arr::uniqueBy($items, $field);
    }

    /**
     * 基于账户类型获取 Business Manager 下的账户（内部方法）
     */
    protected function listBusinessAccountsByType(
        string $businessId,
        string $type,
        array $fields,
        int $limit
    ): array {
        $accounts = $this->client->getAll("/{$businessId}/{$type}", [
            'fields' => $this->formatFields($fields),
            'limit' => $limit,
        ], "GET:/{business_id}/{$type}");

        return $this->deduplicateByField($accounts, 'id');
    }

    /**
     * 基于账户类型流式处理 BM 账户（内部方法）
     */
    protected function iterateBusinessAccountsByType(
        string $businessId,
        string $type,
        array $fields,
        int $limit
    ): \Generator {
        yield from $this->paginateWithDeduplication(
            "/{$businessId}/{$type}",
            ['fields' => $this->formatFields($fields), 'limit' => $limit],
            'id',
            $type
        );
    }

    /**
     * 带去重的分页生成器
     */
    protected function paginateWithDeduplication(
        string $uri,
        array $query,
        string $dedupeField = 'id',
        string $type = ''
    ): \Generator {
        yield from Arr::uniqueGenerator(
            $this->client->paginate($uri, $query, "GET:/{business_id}/{$type}"),
            $dedupeField
        );
    }

    /**
     * 解析默认公司地址：优先静态配置，其次 APP_NAME 兼容旧行为。
     */
    protected function resolveBusinessInfo(): array
    {
        if (self::$defaultBusinessInfo !== null) {
            return self::$defaultBusinessInfo;
        }

        $appName = $this->resolveAppName();
        if ($appName === 'zhixing') {
            return [
                'business_country_code' => 'US',
                'business_street' => '1401 21st Street',
                'business_street2' => 'ste r',
                'business_city' => 'Sacramento',
                'business_state' => 'CA',
                'business_zip' => '95811',
            ];
        }

        return [
            'business_country_code' => 'US',
            'business_street' => 'Beach',
            'business_city' => 'Miami',
            'business_state' => 'FL',
            'business_zip' => '33140',
        ];
    }

    protected function resolveAppName(): string
    {
        if (function_exists('Hyperf\\Support\\env')) {
            return (string) \Hyperf\Support\env('APP_NAME', '');
        }

        return (string) (getenv('APP_NAME') ?: ($_ENV['APP_NAME'] ?? ''));
    }
}