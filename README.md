# ADV SDK

多平台广告 API SDK，目前支持 Facebook Marketing API、TikTok Business API、Google Ads API。

## 安装

```bash
composer require goletter/adv
```

Hyperf 项目可发布配置：

```bash
php bin/hyperf.php vendor:publish goletter/adv
```

## 快速开始（推荐）

```php
use Goletter\Adv\AdvFactory;

// 推荐：平台专用方法，IDE 可跳转到 FacebookAccount 等具体类
$adv = AdvFactory::facebook($accessToken);
// 或多个 token：当前失败则轮询下一个，直到全部试完
// $adv = AdvFactory::facebook([$token1, $token2, $token3]);
// 或 AdvFactory::make('facebook', $accessToken); // 通用入口，效果相同

foreach ($adv->account->iterateAccounts() as $account) {
    // ...
}

$google = AdvFactory::google($oauthToken, [
    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
    'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
]);
```

### Hyperf DI（读取 `config/autoload/adv.php`）

发布配置后，可注入工厂；`create()` 会自动合并对应平台配置（如 `api_version`、`developer_token`）：

```php
use Goletter\Adv\AdvFactory;
use Hyperf\Di\Annotation\Inject;

class AdSyncService
{
    #[Inject]
    protected AdvFactory $advFactory;

    public function sync(string $token): void
    {
        $adv = $this->advFactory->create('facebook', $token);
        // 等价于 AdvFactory::make(...)，容器启动后静态调用也会用到同一配置实例
    }
}
```

### 注册自定义平台

`PlatformBundle` 的 client / account / business / campaign / report 须为已支持平台类型（Facebook / TikTok / Google 对应类），以便 IDE 跳转与类型检查。自定义平台通常基于现有实现扩展或包装：

```php
use Goletter\Adv\AdvFactory;
use Goletter\Adv\PlatformBundle;
use Goletter\Adv\Platforms\Facebook\FacebookAccount;
use Goletter\Adv\Platforms\Facebook\FacebookBusiness;
use Goletter\Adv\Platforms\Facebook\FacebookCampaign;
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookReport;

AdvFactory::register('custom', function (string $accessToken, array $options): PlatformBundle {
    $client = new FacebookClient($accessToken);
    return new PlatformBundle(
        client: $client,
        account: new FacebookAccount($client),
        business: new FacebookBusiness($client),
        campaign: new FacebookCampaign($client),
        report: new FacebookReport($client),
        platform: 'custom',
    );
});

$adv = AdvFactory::make('custom', $token);
```

### 多 Token 轮询（失败切换）

传入 token 数组后，单次请求从当前 token 开始；若抛出 API / Token 失效异常，自动换下一个，直到全部失败才抛出最后一次异常。成功后会粘滞在可用 token。

```php
use Goletter\Adv\AdvFactory;
use Goletter\Adv\Platforms\Facebook\FacebookClient;

$adv = AdvFactory::facebook([$tokenA, $tokenB, $tokenC]);
// 或 AdvFactory::make('facebook', [$tokenA, $tokenB, $tokenC]);

// 也可直接构造 Client
$client = new FacebookClient([$tokenA, $tokenB]);
$client->getAccessTokens();      // ['...', '...']
$client->getAccessToken();       // 当前生效的 token
$client->setAccessTokens([$tokenB, $tokenC]); // 运行时替换池
```

Facebook 在切换 token 前会打 `token-failover` 日志（若配置了 call log handler）。限流会先在当前 token 上按 `max_retries` 退避，仍失败再切下一个 token。

跨平台捕获 Token 失效：

```php
use Goletter\Adv\Exceptions\TokenExpiredExceptionInterface;

try {
    // ...
} catch (TokenExpiredExceptionInterface $e) {
    // facebook / tiktok / google token 失效
}
```

## 使用示例

### Google Ads

需要 OAuth2 **Access Token**、**Developer Token**，操作 MCC 下子账户时还需 **Login Customer ID**（经理账户 ID，纯数字）。

#### 应用授权 OAuth (GoogleAuth)

Google Ads OAuth2 分两步（默认 scope：`https://www.googleapis.com/auth/adwords`）：

1. **授权页**：生成 URL，引导用户同意并返回 `code`
2. **回调**：用 `code` 换 Access Token / Refresh Token（`access_type=offline` + `prompt=consent` 才会返回 refresh_token）

```php
use Goletter\Adv\AdvFactory;
use Goletter\Adv\Platforms\Google\GoogleAuth;

$auth = AdvFactory::googleAuth();
// 或：new GoogleAuth();

// ---------- 1) 生成授权 URL 并跳转 ----------
$state = bin2hex(random_bytes(16)); // 建议落库，回调时校验
$url = $auth->getAuthUrl(
    clientId: 'YOUR_CLIENT_ID',
    redirectUri: 'https://your.domain/api/google/callback', // 须与 Google Cloud 后台一致
    state: $state,
);
// return $this->response->redirect($url);

// ---------- 2) 回调：code 换 Token ----------
// Google 回调参数：code、state（失败时可能有 error / error_description）
$result = $auth->handleCallback(
    clientId: 'YOUR_CLIENT_ID',
    clientSecret: 'YOUR_CLIENT_SECRET',
    redirectUri: 'https://your.domain/api/google/callback', // 须与第 1 步完全一致
    code: (string) $request->input('code'),
);
// $result['access_token']   // 用于 GoogleClient
// $result['refresh_token']  // 首次授权通常有；请持久化
// $result['expires_in']

// 刷新 Access Token：
// $token = $auth->refreshToken($clientId, $clientSecret, $refreshToken);
```

拿到 `access_token` 后即可调用 Ads API：

```php
use Goletter\Adv\Platforms\Google\GoogleClient;
use Goletter\Adv\Platforms\Google\GoogleAccount;
use Goletter\Adv\Platforms\Google\GoogleBusiness;
use Goletter\Adv\Platforms\Google\GoogleReport;
use Goletter\Adv\Platforms\Google\GoogleCampaign;

$client = new GoogleClient(
    accessToken: 'ya29....',
    developerToken: 'YOUR_DEVELOPER_TOKEN',
    loginCustomerId: '1234567890', // 可选，MCC ID
);

$account = new GoogleAccount($client);

// 可访问的客户账户
foreach ($account->iterateAccessibleCustomerIds() as $customerId) {
    echo $customerId . PHP_EOL;
}

// 客户详情
$customer = $account->getCustomer('1234567890');

// GAQL 查询
foreach ($client->iterateSearch('1234567890', 'SELECT campaign.id, campaign.name FROM campaign') as $row) {
    // ...
}

// MCC 下子账户
$business = new GoogleBusiness($client);
foreach ($business->iterateClientCustomers('1234567890') as $clientRow) {
    // ...
}

// 日报表
$report = new GoogleReport($client);
foreach ($report->iterateDailyReport('1234567890', '2026-01-01', '2026-01-31') as $day) {
    // metrics.cost_micros, segments.date ...
}

// 广告系列状态
$campaign = new GoogleCampaign($client);
$campaign->updateCampaignStatus(
    '1234567890',
    'customers/1234567890/campaigns/987654321',
    'PAUSED'
);
```

业务侧也可通过 `AdvFactory::make(3, $oauthToken)` 或 `AdvFactory::make('google', $oauthToken)` 获取平台 Bundle（含 client / account / business / campaign / report）。

环境变量（可选）：

- `GOOGLE_ADS_DEVELOPER_TOKEN`
- `GOOGLE_ADS_LOGIN_CUSTOMER_ID`
- `GOOGLE_ADS_CLIENT_ID` / `GOOGLE_ADS_CLIENT_SECRET` / `GOOGLE_ADS_REDIRECT_URI`（OAuth 授权用）

### 基础 Client

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;

// 创建客户端
$client = new FacebookClient('YOUR_ACCESS_TOKEN');

// 设置自定义请求头（可选）
$client->setDefaultHeaders([
    'requestSource' => 4,
    'Content-Type' => 'application/json',
]);

// 基础 GET 请求
$result = $client->get('/me', ['fields' => 'id,name']);

// 分页获取数据（推荐）
foreach ($client->paginate('/me/adaccounts', ['fields' => 'id,name']) as $account) {
    // 处理每个账户
    echo $account['id'] . PHP_EOL;
}

// ---------- 频率限制（默认已启用自动重试）----------
// 撞限（错误码 4/17/32/613 或 message 含 rate limit）时指数退避重试；
// 也可根据 x-app-usage 主动减速。
$client->configureRateLimit([
    'max_retries' => 5,           // 限流后最多再试 5 次
    'retry_base_delay_ms' => 200, // 退避基数（毫秒），指数增长并带抖动
    'min_interval_ms' => 50,      // 两次请求最小间隔（毫秒），0=不限制
    'usage_threshold' => 80,      // x-app-usage 任一指标 >= 80 时主动等待
    'usage_delay_ms' => 1000,     // 超阈值时额外等待（毫秒）
]);

// 监听用量（可落库 / 告警）
FacebookClient::setAppUsageHandler(function (array $usage, string $token) {
    // $usage: call_count / total_cputime / total_time（0–100）
});

// Graph Batch：一次 HTTP 提交最多 50 个子请求（自动分片 + 走统一限流）
$batchResults = $client->batch([
    ['method' => 'GET', 'relative_url' => 'me?fields=id,name'],
    [
        'method' => 'POST',
        'relative_url' => 'CAMPAIGN_ID',
        'body' => http_build_query(['status' => 'PAUSED']),
    ],
]);
// 每项：['code' => 200, 'success' => true, 'body' => [...], 'error' => '', 'headers' => []]

// AdvFactory 也可传入 rate_limit：
// AdvFactory::make('facebook', $token, ['rate_limit' => ['min_interval_ms' => 50]]);
```

### 应用授权 OAuth (FacebookAuth / dialog/oauth)

Facebook 应用授权分两步：

1. **dialog/oauth**：生成授权页 URL，引导用户跳转 Facebook 登录并授权
2. **回调**：用返回的 `code` 换 Access Token（默认再换长期 Token，约 60 天）

```php
use Goletter\Adv\AdvFactory;
use Goletter\Adv\Platforms\Facebook\FacebookAuth;

// 推荐：工厂创建（OAuth 阶段无需已有 user token）
$auth = AdvFactory::facebookAuth(['api_version' => 'v24.0']);
// 或：new FacebookAuth('v24.0');

// ---------- 1) 生成授权 URL 并跳转 ----------
$state = bin2hex(random_bytes(16)); // 建议落库，回调时校验
$url = $auth->getAuthUrl(
    clientId: 'YOUR_APP_ID',
    redirectUri: 'https://your.domain/api/facebook/callback', // 须与 Meta 后台配置一致
    state: $state,
    // scope 可省略，默认：
    // ads_management,ads_read,business_management,email,public_profile
);
// return $this->response->redirect($url);

// ---------- 2) 回调：code 换长期 Token ----------
// Facebook 回调参数：code、state（失败时可能有 error / error_description）
$result = $auth->handleCallback(
    clientId: 'YOUR_APP_ID',
    clientSecret: 'YOUR_APP_SECRET',
    redirectUri: 'https://your.domain/api/facebook/callback', // 须与第 1 步完全一致
    code: (string) $request->input('code'),
);
// $result['access_token']  长期 User Access Token
// $result['expires_in']    有效秒数（约 60 天）
// $result['short_lived'] / $result['long_lived']  原始分步响应

// 如只需短期 Token，不换长期：
// $auth->handleCallback(..., exchangeLongLived: false);

// 也可分步调用：
// $short = $auth->fetchToken($appId, $appSecret, $redirectUri, $code);
// $long  = $auth->exchangeLongLivedToken($appId, $appSecret, $short['access_token']);
```

也可通过 `FacebookBusiness`（内部委托 `FacebookAuth`）：

```php
use Goletter\Adv\Platforms\Facebook\FacebookBusiness;
use Goletter\Adv\Platforms\Facebook\FacebookClient;

$business = new FacebookBusiness(new FacebookClient('')); // OAuth 阶段可不传 user token
$url = $business->getDialogOauthUrl($appId, $redirectUri, $state);
$token = $business->handleOAuthCallback($appId, $appSecret, $redirectUri, $code);
```

配置项（`config/autoload/adv.php`，发布后可选填写）：

```php
'facebook' => [
    'api_version' => 'v24.0',
    // 'app_id' => env('FACEBOOK_APP_ID', ''),
    // 'app_secret' => env('FACEBOOK_APP_SECRET', ''),
    // 'redirect_uri' => env('FACEBOOK_REDIRECT_URI', ''),
    // 'scopes' => 'ads_management,ads_read,business_management,email,public_profile',
],
```

### Business Manager 管理 (FacebookBusiness)

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookBusiness;

$client = new FacebookClient('YOUR_ACCESS_TOKEN');
$business = new FacebookBusiness($client);

// 获取当前用户的所有 Business Manager
$businesses = $business->listBusinesses();

// 流式处理 Business Manager（推荐）
foreach ($business->iterateBusinesses() as $bm) {
    // 处理每个 BM
    echo $bm['id'] . ': ' . $bm['name'] . PHP_EOL;
}

// 获取单个 Business Manager 详情
$businessDetail = $business->getBusiness('BUSINESS_ID');

// 获取 Business Manager 下的客户广告账户（client_ad_accounts）
$adAccounts = $business->listAdAccounts('BUSINESS_ID');

// 流式处理 BM 下的客户广告账户（推荐）
foreach ($business->iterateAdAccounts('BUSINESS_ID') as $account) {
    // 处理账户
}

// 获取 Business Manager 拥有的广告账户（owned_ad_accounts）
$ownedAccounts = $business->listOwnedAdAccounts('BUSINESS_ID');

// 流式处理 BM 拥有的广告账户（推荐）
foreach ($business->iterateOwnedAdAccounts('BUSINESS_ID') as $account) {
    // 处理账户
}

// 获取 Business Manager 下的业务用户
$users = $business->listBusinessUsers('BUSINESS_ID');

// 流式处理业务用户
foreach ($business->iterateBusinessUsers('BUSINESS_ID') as $user) {
    // 处理用户
}

// 获取 Business Manager 下的 Pages
$pages = $business->listPages('BUSINESS_ID');

// 从 Business Manager 移除广告账户
$result = $business->removeAdAccount('BUSINESS_ID', 'ACCOUNT_ID');

// 批量移除广告账户（内部走 Graph Batch，每批最多 50，自动限流重试）
$results = $business->batchRemoveAdAccounts('BUSINESS_ID', ['ACCOUNT_ID_1', 'ACCOUNT_ID_2']);
```

### 账户管理 (FacebookAccount)

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookAccount;

$client = new FacebookClient('YOUR_ACCESS_TOKEN');
$account = new FacebookAccount($client);

// 获取当前用户的所有广告账户
$accounts = $account->listAccounts();

// 流式处理用户账户（推荐大数据量）
foreach ($account->iterateAccounts() as $acc) {
    // 处理账户
}

// 获取 Business Manager 下的所有广告账户（自动去重）
$businessAccounts = $account->listBusinessAccounts('BUSINESS_ID');

// 流式处理 BM 账户（推荐，自动去重）
foreach ($account->iterateBusinessAccounts('BUSINESS_ID') as $acc) {
    // 处理账户
}

// 获取单个账户详情
$accountDetail = $account->getAccount('ACCOUNT_ID');

// 更新账户名称
$updated = $account->updateAccountName('ACCOUNT_ID', '新的账户名称');

// 更新账户支出限额（以分为单位，100000 = 1000美元）
$updated = $account->updateAccountSpendCap('ACCOUNT_ID', 100000);

// 移除账户限额
$updated = $account->updateAccountSpendCap('ACCOUNT_ID', null);

// 同时更新名称和限额
$updated = $account->updateAccount('ACCOUNT_ID', '新名称', 200000);

// 只更新名称，不更新限额
$updated = $account->updateAccount('ACCOUNT_ID', '新名称');

// 只更新限额，不更新名称
$updated = $account->updateAccount('ACCOUNT_ID', null, 150000);

// 移除限额（传入 -1）
$updated = $account->updateAccount('ACCOUNT_ID', null, -1);

// 从 Business Manager 移除广告账户（注意：不会真正删除账户，只是移除访问权限）
$result = $account->removeAccountFromBusiness('BUSINESS_ID', 'ACCOUNT_ID');

// 获取账户的分配用户列表
$users = $account->listAssignedUsers('ACCOUNT_ID');

// 流式处理分配用户
foreach ($account->iterateAssignedUsers('ACCOUNT_ID') as $user) {
    // 处理每个用户
    echo $user['name'] . ' - ' . $user['role'] . PHP_EOL;
}

// 添加用户到账户（角色：ADMIN, ADVERTISER, ANALYST）
$result = $account->addAssignedUser('ACCOUNT_ID', 'USER_ID', 'ADVERTISER');

// 移除账户用户
$result = $account->removeAssignedUser('ACCOUNT_ID', 'USER_ID');

// 更新用户角色
$result = $account->updateAssignedUserRole('ACCOUNT_ID', 'USER_ID', 'ADMIN');
```

### 广告系列管理 (FacebookCampaign)

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookCampaign;

$client = new FacebookClient('YOUR_ACCESS_TOKEN');
$campaign = new FacebookCampaign($client);

// 获取账户下的所有广告系列
$campaigns = $campaign->listCampaigns('ACCOUNT_ID');

// 获取特定状态的广告系列
$activeCampaigns = $campaign->listCampaigns('ACCOUNT_ID', [
    'id',
    'name',
    'objective',
    'status',
    'effective_status',
    'daily_budget',
    'budget_remaining'
], [
    'status' => ['ACTIVE'],
    'effective_status' => ['ACTIVE']
]);

// 流式处理广告系列（推荐大数据量，自动去重）
foreach ($campaign->iterateCampaigns('ACCOUNT_ID') as $item) {
    // 处理每个广告系列
    echo $item['name'] . ' - ' . $item['status'] . PHP_EOL;
}

// 获取单个广告系列详情
$campaignDetail = $campaign->getCampaign('CAMPAIGN_ID');

// 创建广告系列
$newCampaign = $campaign->createCampaign(
    'ACCOUNT_ID',
    '我的广告系列',
    'OUTCOME_TRAFFIC', // 广告目标
    'PAUSED', // 初始状态
    [
        'daily_budget' => 10000, // 每日预算（分为单位）
        // 'lifetime_budget' => 100000, // 或设置总预算
        // 'start_time' => '2024-01-01T00:00:00+0000',
        // 'stop_time' => '2024-12-31T23:59:59+0000',
    ]
);

// 更新广告系列
$updated = $campaign->updateCampaign('CAMPAIGN_ID', [
    'name' => '新名称',
    'daily_budget' => 20000,
    'status' => 'ACTIVE',
]);

// 更新广告系列状态
$campaign->pauseCampaign('CAMPAIGN_ID'); // 暂停
$campaign->activateCampaign('CAMPAIGN_ID'); // 启用
$campaign->deleteCampaign('CAMPAIGN_ID'); // 删除
$campaign->archiveCampaign('CAMPAIGN_ID'); // 归档

// 批量更新状态（内部走 Graph Batch，每批最多 50，自动限流重试）
$results = $campaign->batchUpdateStatus(['CAMPAIGN_ID_1', 'CAMPAIGN_ID_2'], 'PAUSED');
```

### 报告查询 (FacebookReport)

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookReport;

$client = new FacebookClient('YOUR_ACCESS_TOKEN');
$report = new FacebookReport($client);

// 获取账户级别的 insights
foreach ($report->iterateInsights(
    'ACCOUNT_ID',
    '2024-01-01',
    '2024-01-31',
    ['account_name', 'account_id', 'spend', 'date_start', 'date_stop'],
    'account',  // level: account, campaign, adset, ad
    1  // time_increment: 1 (每日)
) as $insight) {
    // 处理每个 insight
}

// 获取每日报告（按天拆分，推荐长时间范围）
foreach ($report->iterateDailyReport(
    'ACCOUNT_ID',
    '2024-01-01',
    '2024-01-31'
) as $dailyData) {
    // 处理每日数据
}

// 一次性获取所有数据（不推荐大数据量）
$allInsights = $report->getAllInsights(
    'ACCOUNT_ID',
    '2024-01-01',
    '2024-01-31'
);

// 分页获取消耗数据（推荐，使用 Cursor 分页）
$page1 = $report->paginateInsights(
    'ACCOUNT_ID',
    '2024-01-01',
    '2024-01-31',
    null, // after cursor（第一页传 null）
    null, // before cursor
    100   // 每页数量
);

// 获取下一页
if (isset($page1['paging']['cursors']['after'])) {
    $page2 = $report->paginateInsights(
        'ACCOUNT_ID',
        '2024-01-01',
        '2024-01-31',
        $page1['paging']['cursors']['after'] // 使用上一页返回的 after cursor
    );
}

// 处理分页数据
$data = $page1['data'];
$hasNext = isset($page1['paging']['next']);
$hasPrevious = isset($page1['paging']['previous']);
```

### 在项目中的实际使用示例

#### 获取 Business Manager 列表

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookBusiness;

// 从平台模型获取 token
$platform = $platformModel;

// 创建客户端
$client = new FacebookClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

// 获取所有 Business Manager
$businessService = new FacebookBusiness($client);
foreach ($businessService->iterateBusinesses() as $item) {
    Busines::query()->updateOrCreate(
        ['code' => $item['id']],
        [
            'platform_id' => $platform->id,
            'code' => $item['id'],
            'name' => $item['name'],
        ]
    );
}
```

#### 获取 BM 下的广告账户

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookBusiness;
use Carbon\Carbon;

$client = new FacebookClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

$businessService = new FacebookBusiness($client);
$busine = $busineModel;

// 获取 BM 下的客户账户（client_ad_accounts，自动去重）
foreach ($businessService->iterateAdAccounts($busine->code) as $item) {
    Account::query()->updateOrCreate(
        ['code' => $item['id']],
        [
            'busine_id' => $busine->id,
            'code' => $item['id'],
            'name' => $item['name'],
            'status' => $item['account_status'],
            'spend_cap' => $item['spend_cap'],
            'amount_spent' => $item['amount_spent'],
            'currency' => $item['currency'],
            'timezone' => $item['timezone_offset_hours_utc'],
            'created_at' => Carbon::parse($item['created_time'])->format('Y-m-d H:i:s'),
        ]
    );
}

// 获取 BM 拥有的账户（owned_ad_accounts，自动去重）
foreach ($businessService->iterateOwnedAdAccounts($busine->code) as $item) {
    Account::query()->updateOrCreate(
        ['code' => $item['id']],
        [
            'busine_id' => $busine->id,
            'code' => $item['id'],
            'name' => $item['name'],
            'status' => $item['account_status'],
            'spend_cap' => $item['spend_cap'],
            'amount_spent' => $item['amount_spent'],
            'currency' => $item['currency'],
            'timezone' => $item['timezone_offset_hours_utc'],
            'created_at' => Carbon::parse($item['created_time'])->format('Y-m-d H:i:s'),
        ]
    );
}

// 从 Business Manager 移除广告账户
try {
    $result = $businessService->removeAdAccount($busine->code, $account->code);
    // 移除成功，可以从数据库中删除相关记录
    // $account->delete();
} catch (\Exception $e) {
    // 处理错误
}

// 批量移除广告账户
try {
    $accountIds = ['ACCOUNT_ID_1', 'ACCOUNT_ID_2'];
    $results = $businessService->batchRemoveAdAccounts($busine->code, $accountIds);
    
    foreach ($results as $accountId => $result) {
        if ($result['success']) {
            // 移除成功
        } else {
            // 处理错误：$result['error']
        }
    }
} catch (\Exception $e) {
    // 处理错误
}
```

#### 获取账户消耗数据

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookReport;
use Carbon\Carbon;

$client = new FacebookClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

$account = $accountModel;
$startAt = Carbon::now()->subMonths(1)->toDateString();
$endAt = Carbon::now()->toDateString();

$reportService = new FacebookReport($client);

// 方式 1：流式处理所有数据（推荐大数据量）
foreach ($reportService->iterateInsights(
    $account->code,
    $startAt,
    $endAt,
    ['account_name', 'account_id', 'spend', 'date_start', 'date_stop'],
    'account',
    1
) as $insight) {
    AccountInsight::query()->updateOrCreate(
        [
            'account_id' => $account->id,
            'start_at' => $insight['date_start'],
            'end_at' => $insight['date_stop'],
        ],
        ['spend' => $insight['spend']]
    );
}

// 方式 2：分页获取消耗数据（推荐用于 API 接口返回）
try {
    // 获取第一页
    $page = $reportService->paginateInsights(
        $account->code,
        $startAt,
        $endAt,
        null, // after cursor（第一页传 null）
        null, // before cursor
        100   // 每页数量
    );
    
    // 处理当前页数据
    foreach ($page['data'] as $insight) {
        AccountInsight::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'start_at' => $insight['date_start'],
                'end_at' => $insight['date_stop'],
            ],
            ['spend' => $insight['spend']]
        );
    }
    
    // 判断是否有下一页
    $hasNext = isset($page['paging']['next']);
    $hasPrevious = isset($page['paging']['previous']);
    
    // 获取下一页（循环处理所有页）
    $after = $page['paging']['cursors']['after'] ?? null;
    while ($after) {
        $nextPage = $reportService->paginateInsights(
            $account->code,
            $startAt,
            $endAt,
            $after,
            null,
            100
        );
        
        foreach ($nextPage['data'] as $insight) {
            AccountInsight::query()->updateOrCreate(
                [
                    'account_id' => $account->id,
                    'start_at' => $insight['date_start'],
                    'end_at' => $insight['date_stop'],
                ],
                ['spend' => $insight['spend']]
            );
        }
        
        // 更新 cursor，继续下一页
        $after = $nextPage['paging']['cursors']['after'] ?? null;
    }
} catch (\Exception $e) {
    // 处理错误
}
```

#### 更新账户名称和限额

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookAccount;

$client = new FacebookClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

$accountService = new FacebookAccount($client);
$account = $accountModel;

// 更新账户名称
try {
    $result = $accountService->updateAccountName($account->code, '新账户名称');
    $account->update(['name' => '新账户名称']);
} catch (\Exception $e) {
    // 处理错误
}

// 更新账户限额（1000美元 = 100000分）
try {
    $spendCap = 100000; // 1000美元
    $result = $accountService->updateAccountSpendCap($account->code, $spendCap);
    $account->update(['spend_cap' => $spendCap]);
} catch (\Exception $e) {
    // 处理错误
}

// 同时更新名称和限额
try {
    $result = $accountService->updateAccount(
        $account->code,
        '新账户名称',
        200000 // 2000美元
    );
    $account->update([
        'name' => '新账户名称',
        'spend_cap' => 200000,
    ]);
} catch (\Exception $e) {
    // 处理错误
}

// 移除限额
try {
    $result = $accountService->updateAccountSpendCap($account->code, null);
    $account->update(['spend_cap' => null]);
} catch (\Exception $e) {
    // 处理错误
}
```

#### 管理账户分配用户

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookAccount;

$client = new FacebookClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

$accountService = new FacebookAccount($client);
$account = $accountModel;

// 获取账户的分配用户列表
try {
    $users = $accountService->listAssignedUsers($account->code);
    foreach ($users as $user) {
        // 处理用户信息：$user['id'], $user['name'], $user['email'], $user['role']
    }
} catch (\Exception $e) {
    // 处理错误
}

// 流式处理分配用户（推荐大数据量）
try {
    foreach ($accountService->iterateAssignedUsers($account->code) as $user) {
        // 处理每个用户
    }
} catch (\Exception $e) {
    // 处理错误
}

// 添加用户到账户（角色：ADMIN, ADVERTISER, ANALYST）
try {
    $facebookUserId = 'FACEBOOK_USER_ID';
    $result = $accountService->addAssignedUser(
        $account->code,
        $facebookUserId,
        'ADVERTISER' // 角色选项：ADMIN, ADVERTISER, ANALYST
    );
    // 添加成功
} catch (\Exception $e) {
    // 处理错误
}

// 移除用户
try {
    $result = $accountService->removeAssignedUser(
        $account->code,
        'FACEBOOK_USER_ID'
    );
    // 移除成功
} catch (\Exception $e) {
    // 处理错误
}

// 更新用户角色
try {
    $result = $accountService->updateAssignedUserRole(
        $account->code,
        'FACEBOOK_USER_ID',
        'ADMIN' // 新角色：ADMIN, ADVERTISER, ANALYST
    );
    // 更新成功
} catch (\Exception $e) {
    // 处理错误
}
```

#### 管理广告系列

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookCampaign;

$client = new FacebookClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

$campaignService = new FacebookCampaign($client);
$account = $accountModel;

// 获取账户下的所有活跃广告系列
try {
    $campaigns = $campaignService->listCampaigns(
        $account->code,
        ['id', 'name', 'objective', 'status', 'effective_status', 'daily_budget'],
        ['status' => ['ACTIVE']]
    );
    
    foreach ($campaigns as $campaign) {
        // 处理广告系列
    }
} catch (\Exception $e) {
    // 处理错误
}

// 流式处理所有广告系列
try {
    foreach ($campaignService->iterateCampaigns($account->code) as $campaign) {
        // 处理每个广告系列
    }
} catch (\Exception $e) {
    // 处理错误
}

// 创建广告系列
try {
    $newCampaign = $campaignService->createCampaign(
        $account->code,
        '新广告系列',
        'OUTCOME_TRAFFIC',
        'PAUSED',
        ['daily_budget' => 10000] // 100美元
    );
} catch (\Exception $e) {
    // 处理错误
}

// 批量暂停广告系列
try {
    $campaignIds = ['CAMPAIGN_ID_1', 'CAMPAIGN_ID_2'];
    $results = $campaignService->batchUpdateStatus($campaignIds, 'PAUSED');
    
    foreach ($results as $campaignId => $result) {
        if ($result['success']) {
            // 更新成功
        } else {
            // 处理错误：$result['error']
        }
    }
} catch (\Exception $e) {
    // 处理错误
}
```

### 异常处理

```php
use Goletter\Adv\Platforms\Facebook\Exceptions\FacebookApiException;
use Goletter\Adv\Platforms\Facebook\Exceptions\FacebookTokenExpiredException;

try {
    $accounts = $account->listAccounts();
} catch (FacebookTokenExpiredException $e) {
    // Token 已过期，需要刷新
    // 错误码: 190
} catch (FacebookApiException $e) {
    // 其他 Facebook API 错误
    $errorData = $e->getResponse();
}
```

## API 版本

默认使用 Facebook API v24.0，可在创建 Client 时自定义：

```php
$client = new FacebookClient('TOKEN', 'v19.0');
```

### 在账户类中快捷访问广告系列

```php
use Goletter\Adv\Platforms\Facebook\FacebookClient;
use Goletter\Adv\Platforms\Facebook\FacebookAccount;

$client = new FacebookClient('YOUR_ACCESS_TOKEN');
$account = new FacebookAccount($client);

// 直接通过账户类获取广告系列（快捷方法）
$campaigns = $account->listCampaigns('ACCOUNT_ID');

// 流式处理
foreach ($account->iterateCampaigns('ACCOUNT_ID') as $campaign) {
    // 处理广告系列
}
```

## TikTok 使用示例

### 应用授权 OAuth (TikTokAuth / portal/auth)

TikTok Marketing API 应用授权分两步：

1. **portal/auth**：生成授权页 URL，引导用户授权广告账户
2. **回调**：用返回的 `auth_code` 换 Access Token / Refresh Token

```php
use Goletter\Adv\AdvFactory;
use Goletter\Adv\Platforms\TikTok\TikTokAuth;

// 推荐：工厂创建（OAuth 阶段无需已有 access token）
$auth = AdvFactory::tiktokAuth();
// 或：new TikTokAuth('https://business-api.tiktok.com');

// ---------- 1) 生成授权 URL 并跳转 ----------
$state = bin2hex(random_bytes(16)); // 建议落库，回调时校验
$url = $auth->getAuthUrl(
    appId: 'YOUR_APP_ID',
    redirectUri: 'https://your.domain/api/tiktok/callback', // 须与 TikTok 后台配置一致
    state: $state,
);
// return $this->response->redirect($url);

// ---------- 2) 回调：auth_code 换 Token ----------
// TikTok 回调参数：auth_code、state（失败时可能有 code / message）
$result = $auth->handleCallback(
    appId: 'YOUR_APP_ID',
    secret: 'YOUR_APP_SECRET',
    authCode: (string) $request->input('auth_code'),
);
// $result['access_token']
// $result['refresh_token']
// $result['expires_in'] / $result['refresh_expires_in']
// $result['advertiser_ids']

// 也可分步：
// $token = $auth->fetchToken($appId, $secret, $authCode);
// $token = $auth->refreshToken($appId, $secret, $refreshToken);
```

配置项（`config/autoload/adv.php`，发布后可选填写）：

```php
'tiktok' => [
    'base_uri' => 'https://business-api.tiktok.com',
    // 'app_id' => env('TIKTOK_APP_ID', ''),
    // 'secret' => env('TIKTOK_APP_SECRET', ''),
    // 'redirect_uri' => env('TIKTOK_REDIRECT_URI', ''),
],
```

### 基础 Client

```php
use Goletter\Adv\Platforms\TikTok\TikTokClient;

// 创建 TikTok 客户端
$client = new TikTokClient('YOUR_ACCESS_TOKEN');

// 设置自定义请求头（可选）
$client->setDefaultHeaders([
    'requestSource' => 4,
]);

// 示例：调用 TikTok 接口
$result = $client->get('/open_api/v1.3/advertiser/info/', [
    'advertiser_id' => 'YOUR_ADVERTISER_ID',
]);
```

### 报表查询 (TikTokReport)

```php
use Goletter\Adv\Platforms\TikTok\TikTokClient;
use Goletter\Adv\Platforms\TikTok\TikTokReport;

$client = new TikTokClient('YOUR_ACCESS_TOKEN');
$client->setDefaultHeaders(['requestSource' => 4]);

$report = new TikTokReport($client);

// 获取广告主的基础消耗报表（按天）
foreach ($report->iterateReport(
    'YOUR_ADVERTISER_ID',
    '2024-01-01',
    '2024-01-31',
    ['stat_time_day'],                       // 维度
    ['spend', 'impressions', 'clicks']      // 指标
) as $row) {
    // 处理每一行报表数据
    // $row['stat_time_day'], $row['spend'], $row['impressions'], $row['clicks'] ...
}
```

### 广告主信息 (TikTokAccount)

```php
use Goletter\Adv\Platforms\TikTok\TikTokClient;
use Goletter\Adv\Platforms\TikTok\TikTokAccount;

$client = new TikTokClient('YOUR_ACCESS_TOKEN');
$client->setDefaultHeaders(['requestSource' => 4]);

$accountService = new TikTokAccount($client);

// 获取单个广告主信息
$advertiser = $accountService->getAdvertiser('YOUR_ADVERTISER_ID');

// 批量获取广告主信息
$advertisers = $accountService->listAdvertisers([
    'ADVERTISER_ID_1',
    'ADVERTISER_ID_2',
]);
```

### 在项目中的实际使用示例（同步 TikTok 消耗到数据库）

```php
use Goletter\Adv\Platforms\TikTok\TikTokClient;
use Goletter\Adv\Platforms\TikTok\TikTokReport;
use Carbon\Carbon;

// 从平台模型获取 TikTok token 和 advertiser_id
$platform = $platformModel;

$client = new TikTokClient($platform->token);
$client->setDefaultHeaders(['requestSource' => 4]);

$reportService = new TikTokReport($client);

$startAt = Carbon::now()->subMonth()->toDateString();
$endAt = Carbon::now()->toDateString();

foreach ($reportService->iterateReport(
    $platform->advertiser_id,
    $startAt,
    $endAt,
    ['stat_time_day'],
    ['spend', 'impressions', 'clicks']
) as $row) {
    AccountInsight::query()->updateOrCreate(
        [
            'account_id' => $account->id,
            'start_at' => $row['stat_time_day'],
            'end_at' => $row['stat_time_day'],
        ],
        [
            'spend' => $row['spend'],
            // 如有需要可额外存 impressions/clicks 等字段
        ]
    );
}
```

### TikTok 异常处理

```php
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokApiException;
use Goletter\Adv\Platforms\TikTok\Exceptions\TikTokTokenExpiredException;

try {
    $result = $client->get('/open_api/v1.3/advertiser/info/', [
        'advertiser_id' => 'YOUR_ADVERTISER_ID',
    ]);
} catch (TikTokTokenExpiredException $e) {
    // Access-Token 失效，需要刷新
} catch (TikTokApiException $e) {
    // 其他 TikTok API 错误
    $errorData = $e->getResponse();
}
```

## 特性

- ✅ 流式分页处理（Generator），内存友好
- ✅ 自动处理 Facebook 分页
- ✅ 自动去重（基于 ID），避免重复数据
- ✅ 完整的错误处理和异常类型
- ✅ 支持自定义请求头
- ✅ 支持 BM 账户查询
- ✅ 支持多级别 insights 报告
- ✅ 灵活的时间范围查询
- ✅ 完整的广告系列（Campaigns）管理
- ✅ 支持过滤条件和批量操作
- ✅ Facebook 应用授权（dialog/oauth + 回调换长期 Token）
- ✅ TikTok 应用授权（portal/auth + auth_code 换 Token / refresh）
- ✅ Google Ads 应用授权（OAuth2 authorize + code/refresh 换 Token）
