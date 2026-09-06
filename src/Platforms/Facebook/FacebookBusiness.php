<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

class FacebookBusiness
{
    protected ?FacebookAccount $account = null;

    public function __construct(
        protected FacebookClient $client
    ) {}

    protected function account(): FacebookAccount
    {
        return $this->account ??= new FacebookAccount($this->client);
    }

    /**
     * 获取当前用户的所有 Business Manager
     * 
     * @param array $fields 要获取的字段列表
     * @param int $limit 每页限制
     * @return array
     */
    public function listBusinesses(
        array $fields = ['id', 'name', 'timezone_id', 'verification_status'],
        int $limit = 100
    ): array {
        return $this->client->getAll('/me/businesses', [
            'fields' => implode(',', $fields),
            'limit' => $limit,
        ], 'GET:/me/businesses');
    }

    /**
     * 流式处理 Business Manager（推荐大数据量）
     */
    public function iterateBusinesses(
        array $fields = ['id', 'name', 'timezone_id', 'verification_status'],
        int $limit = 100
    ): \Generator {
        return $this->client->paginate('/me/businesses', [
            'fields' => implode(',', $fields),
            'limit' => $limit,
        ], 'GET:/me/businesses');
    }

    /**
     * 获取单个 Business Manager 详情
     * 
     * @param string $businessId Business Manager ID
     * @param array $fields 要获取的字段列表
     * @return array
     */
    public function getBusiness(
        string $businessId,
        array $fields = ['id', 'name', 'timezone_id', 'verification_status']
    ): array {
        return $this->client->get("/{$businessId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{business_id}');
    }

    /**
     * 获取 Business Manager 下的客户广告账户（client_ad_accounts）
     * 自动去重，基于账户 ID
     */
    public function listAdAccounts(
        string $businessId,
        array $fields = FacebookAccount::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): array {
        return $this->account()->listBusinessAccounts($businessId, $fields, $limit);
    }

    /**
     * 流式处理 Business Manager 下的客户广告账户（推荐大数据量）
     * 自动去重，基于账户 ID
     */
    public function iterateAdAccounts(
        string $businessId,
        array $fields = FacebookAccount::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): \Generator {
        yield from $this->account()->iterateBusinessAccounts($businessId, $fields, $limit);
    }

    /**
     * 获取 Business Manager 拥有的广告账户（owned_ad_accounts）
     * 自动去重，基于账户 ID
     */
    public function listOwnedAdAccounts(
        string $businessId,
        array $fields = FacebookAccount::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): array {
        return $this->account()->listOwnedBusinessAccounts($businessId, $fields, $limit);
    }

    /**
     * 流式处理 Business Manager 拥有的广告账户（推荐大数据量）
     * 自动去重，基于账户 ID
     */
    public function iterateOwnedAdAccounts(
        string $businessId,
        array $fields = FacebookAccount::DEFAULT_ACCOUNT_FIELDS,
        int $limit = 1000
    ): \Generator {
        yield from $this->account()->iterateOwnedBusinessAccounts($businessId, $fields, $limit);
    }

    /**
     * 获取 Business Manager 下的业务用户列表
     * 
     * @param string $businessId Business Manager ID
     * @param array $fields 要获取的字段列表
     * @return array
     */
    public function listBusinessUsers(
        string $businessId,
        array $fields = ['id', 'name', 'email', 'role']
    ): array {
        return $this->client->getAll("/{$businessId}/business_users", [
            'fields' => implode(',', $fields),
        ], 'GET:/{business_id}/business_users');
    }

    /**
     * 流式处理 Business Manager 下的业务用户
     */
    public function iterateBusinessUsers(
        string $businessId,
        array $fields = ['id', 'name', 'email', 'role']
    ): \Generator {
        return $this->client->paginate("/{$businessId}/business_users", [
            'fields' => implode(',', $fields),
        ], 'GET:/{business_id}/business_users');
    }

    /**
     * 获取 Business Manager 下的 Pages
     * 
     * @param string $businessId Business Manager ID
     * @param array $fields 要获取的字段列表
     * @return array
     */
    public function listPages(
        string $businessId,
        array $fields = ['id', 'name', 'category']
    ): array {
        return $this->client->getAll("/{$businessId}/owned_pages", [
            'fields' => implode(',', $fields),
        ], 'GET:/{business_id}/owned_pages');
    }

    /**
     * 流式处理 Business Manager 下的 Pages
     */
    public function iteratePages(
        string $businessId,
        array $fields = ['id', 'name', 'category']
    ): \Generator {
        return $this->client->paginate("/{$businessId}/owned_pages", [
            'fields' => implode(',', $fields),
        ], 'GET:/{business_id}/owned_pages');
    }

    /**
     * 从 Business Manager 移除广告账户
     * 注意：此操作会从 Business Manager 中移除广告账户的访问权限，并不会真正删除账户
     * 
     * @param string $businessId Business Manager ID
     * @param string $accountId 账户ID（不需要 act_ 前缀）
     * @return array API 响应
     */
    public function removeAdAccount(string $businessId, string $accountId): array
    {
        return $this->account()->removeAccountFromBusiness($businessId, $accountId);
    }

    /**
     * 批量从 Business Manager 移除广告账户
     * 
     * @param string $businessId Business Manager ID
     * @param array $accountIds 账户ID数组（不需要 act_ 前缀）
     * @return array 移除结果，格式：['account_id' => ['success' => true/false, 'data'/'error' => ...]]
     */
    public function batchRemoveAdAccounts(
        string $businessId,
        array $accountIds
    ): array {
        $results = [];
        
        foreach ($accountIds as $accountId) {
            try {
                $results[$accountId] = [
                    'success' => true,
                    'data' => $this->removeAdAccount($businessId, $accountId),
                ];
            } catch (\Exception $e) {
                $results[$accountId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }

    /**
     * 邀请 BusinessUser
     */
    public function inviteBusinessUser(
        string $businessId,
        string $email,
        string $access_token,
        string $role = 'EMPLOYEE'
    ): array {
        return $this->client->post("/{$businessId}/business_users", [
            'email' => $email,
            'role'  => $role,
            'access_token'  => $access_token,
        ], [], "POST:/{business_id}/business_users");
    }
    
    /**
     * 流式处理 Business Manager 下的系统用户
     * 
     */
    
    public function iterateSystemUsers(
        string $businessId,
        array $fields = ['id', 'name', 'role']
    ): array {
        return $this->client->getAll("/{$businessId}/system_users", [
            'fields' => implode(',', $fields),
        ], "GET:/{business_id}/system_users");
    }


    /**
      * @desc 获取business下的邮箱
      * @param string $businessId business的code字段
      * @return []
      * @author zhouzhou 2026-06-26
      */
    public function getBusinessUsers(
        string $businessId,
        int $limit = 15,
        string $after = '',
        array $fields = ['id', 'email']
    ): array
    {
        $fields = array_values(array_filter(array_map('trim', $fields)));
        if ($fields === []) {
            $fields = ['id', 'email'];
        }

        $query = [
            'fields' => implode(',', $fields),
            'limit' => $limit,
        ];
        if ($after !== '') {
            $query['after'] = $after;
        }

        return $this->client->get("/{$businessId}/business_users", $query, "GET:/{business_id}/business_users");
    }


    /**
      * @desc 获取邀请的邮箱数据
      * @param string $businessId business的code字段
      * @return []
      * @author zhouzhou 2026-06-26
      */
    public function getPendingUsers(string $businessId, int $limit = 1500): array
    {
        return $this->client->get("/{$businessId}/pending_users", [
            'fields' => 'id,email,role',
            'limit' => $limit,
        ], "GET:/{business_id}/pending_users");
    }

    /**
     * 获取邀请中的邮箱数据-分页拉(数据量大时用)
     *
     * @param string $businessId
     * @param array $fields
     * @param integer $limit
     * @return array
     */
    public function iteratePendingUsers(string $businessId, array $fields = ['created_time', 'id', 'email', 'role', 'status'], int $limit = 1500): array
    {
        return $this->client->getAll("/{$businessId}/pending_users", [
            'fields' => implode(',', $fields),
            'limit' => $limit,
        ], "GET:/{business_id}/pending_users");
    }

    /**
     * 移除邀请中的邮箱
     *
     * @param string $pendingUserId
     * @return array
     */
    public function deletePendingUser(string $pendingUserId): array
    {
        return $this->client->delete("/{$pendingUserId}", [], "DELETE:/{business_role_request_id}");
    }

    /**
     * 移除 BM 下已加入的个号
     */
    public function deleteBusinessUser(string $businessUserId): array
    {
        return $this->client->delete("/{$businessUserId}", [], "DELETE:/{business_user_id}");
    }
    
    /**
     * 获取me(用于探针检测 total_time)
     *
     * @return array
     */
    public function getMe(): array
    {
        return $this->client->get("/me", [], "GET:/me");
    }

    /**
     * 查询待审批的像素
     * GET /{business_id}/pending_shared_offsite_signal_container_business_objects
     * primary_container_id = 像素ID
     */
    public function getPendingSharedPixels(
        string $businessId,
        array $fields = ['id', 'primary_container_id', 'business', 'name', 'is_unavailable', 'agreement']
    ): array {
        return $this->client->get("/{$businessId}/pending_shared_offsite_signal_container_business_objects", [
            'fields' => implode(',', $fields),
        ], 'GET:/{business_id}/pending_shared_offsite_signal_container_business_objects');
    }

    /**
     * 接收像素：审批资产共享协议
     * POST /{agreement_id}?request_status=APPROVE
     */
    public function approveAssetSharingAgreement(string $agreementId): array
    {
        return $this->client->post("/{$agreementId}", [
            'request_status' => 'APPROVE',
        ], [], 'POST:/{agreement_id}');
    }

    /**
     * 像素共享到广告账户
     * POST /{pixel_id}/shared_accounts
     */
    public function sharePixelToAdAccount(string $pixelId, string $businessId, string $accountId): array
    {
        $accountId = preg_replace('/^act_/i', '', $accountId) ?: $accountId;

        return $this->client->post("/{$pixelId}/shared_accounts", [
            'business' => $businessId,
            'account_id' => $accountId,
        ], [], 'POST:/{pixel_id}/shared_accounts');
    }

    /**
     * 像素已共享的广告账户列表
     * GET /{pixel_id}/shared_accounts?business={business_id}&fields=id,name,account_status
     */
    public function getPixelSharedAccounts(
        string $pixelId,
        string $businessId,
        array $fields = ['id', 'name', 'account_status']
    ): array {
        return $this->client->getAll("/{$pixelId}/shared_accounts", [
            'business' => $businessId,
            'fields' => implode(',', $fields),
            'limit' => 500,
        ], 'GET:/{pixel_id}/shared_accounts');
    }

    /**
     * BM 下客户端像素列表
     * GET /{business_id}/client_pixels?fields=id,name
     */
    public function getClientPixels(string $businessId, array $fields = ['id', 'name']): array
    {
        return $this->client->getAll("/{$businessId}/client_pixels", [
            'fields' => implode(',', $fields),
            'limit' => 500,
        ], 'GET:/{business_id}/client_pixels');
    }

    /**
     * BM 下自有像素列表
     * GET /{business_id}/owned_pixels?fields=id,name
     */
    public function getOwnedPixels(string $businessId, array $fields = ['id', 'name']): array
    {
        return $this->client->getAll("/{$businessId}/owned_pixels", [
            'fields' => implode(',', $fields),
            'limit' => 500,
        ], 'GET:/{business_id}/owned_pixels');
    }

    /**
     * 按个号ID查询用户信息（含真实邮箱）
     * GET /{user_id}?fields=name,email
     */
    public function getBusinessUser(string $userId, array $fields = ['id', 'name', 'email']): array
    {
        return $this->client->get("/{$userId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{user_id}');
    }

    /**
     * 生成应用授权 URL
     * https://www.facebook.com/{version}/dialog/oauth
     *
     * @param string|array|null $scope 权限列表，默认广告管理相关 scope
     * @param array<string, scalar|null> $extra 额外 query 参数
     */
    public function getDialogOauthUrl(
        string $clientId,
        string $redirectUri,
        ?string $state = null,
        string|array|null $scope = null,
        string $responseType = 'code',
        array $extra = []
    ): string {
        return $this->auth()->getAuthUrl($clientId, $redirectUri, $state, $scope, $responseType, $extra);
    }

    /**
     * authorization code 换短期 User Access Token
     * GET /oauth/access_token
     */
    public function getAccessTokenByCode(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code
    ): array {
        return $this->auth()->fetchToken($clientId, $clientSecret, $redirectUri, $code);
    }

    /**
     * 短期 Token 换长期 Token（约 60 天）
     * GET /oauth/access_token?grant_type=fb_exchange_token
     */
    public function exchangeLongLivedToken(
        string $clientId,
        string $clientSecret,
        string $shortLivedToken
    ): array {
        return $this->auth()->exchangeLongLivedToken($clientId, $clientSecret, $shortLivedToken);
    }

    /**
     * OAuth 回调一站式：code → 短期 Token → 长期 Token
     *
     * @return array{access_token: string, expires_in?: int, short_lived?: array, long_lived?: array, ...}
     */
    public function handleOAuthCallback(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code,
        bool $exchangeLongLived = true
    ): array {
        return $this->auth()->handleCallback(
            $clientId,
            $clientSecret,
            $redirectUri,
            $code,
            $exchangeLongLived
        );
    }

    protected function auth(): FacebookAuth
    {
        return new FacebookAuth($this->client->getApiVersion());
    }
}
