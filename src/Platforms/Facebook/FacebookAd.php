<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook;

use Goletter\Adv\Support\Arr;
use InvalidArgumentException;

/**
 * Facebook 广告投放：素材上传、广告组、创意、广告。
 *
 * 典型流程：uploadImage/uploadVideo → createAdset → createCreative → createAd
 */
class FacebookAd
{
    public const DEFAULT_ADSET_FIELDS = [
        'id',
        'name',
        'campaign_id',
        'status',
        'effective_status',
        'daily_budget',
        'lifetime_budget',
        'budget_remaining',
        'billing_event',
        'optimization_goal',
        'created_time',
        'updated_time',
    ];

    public const DEFAULT_CREATIVE_FIELDS = [
        'id',
        'name',
        'status',
        'object_story_spec',
        'thumbnail_url',
        'image_hash',
        'video_id',
        'title',
        'body',
        'call_to_action_type',
    ];

    public const DEFAULT_AD_FIELDS = [
        'id',
        'name',
        'adset_id',
        'campaign_id',
        'status',
        'effective_status',
        'creative',
        'created_time',
        'updated_time',
    ];

    protected const VALID_STATUSES = ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'];

    public function __construct(
        protected FacebookClient $client
    ) {}

    /* ==================== 素材上传 ==================== */

    /**
     * 上传广告图片，返回 Graph 原始结果（images.*.hash）。
     *
     * @param string $accountId 账户 ID，可带或不带 act_ 前缀
     */
    public function uploadImage(string $accountId, string $filePath, string $mime = '', string $filename = ''): array
    {
        $accountId = $this->normalizeAccountId($accountId);
        [$bytes, $defaultName] = $this->readImage($filePath);
        $name = $filename !== '' ? $filename : $defaultName;
        if ($mime === '') {
            $mime = $this->guessMime($name, $bytes);
        }

        // 用字符串 contents，避免 fopen 资源在协程里被提前关掉；不要走 base64 bytes（4MB+ 图会膨胀且易触发 100-continue）
        return $this->client->postMultipart(
            "/act_{$accountId}/adimages",
            [],
            [[
                'name' => 'filename',
                'contents' => $bytes,
                'filename' => $name,
                'mime' => $mime,
            ]],
            [],
            'POST:/act_{ad_account_id}/adimages'
        );
    }

    /**
     * 上传图片并返回 image_hash（创建创意常用）。
     */
    public function uploadImageHash(string $accountId, string $filePath, string $mime = '', string $filename = ''): string
    {
        $result = $this->uploadImage($accountId, $filePath, $mime, $filename);
        $images = $result['images'] ?? [];
        if (! is_array($images) || $images === []) {
            throw new InvalidArgumentException('上传图片成功但未返回 images.hash');
        }

        $first = reset($images);
        $hash = is_array($first) ? (string) ($first['hash'] ?? '') : '';
        if ($hash === '') {
            throw new InvalidArgumentException('上传图片成功但未返回 hash');
        }

        return $hash;
    }

    /**
     * 上传广告视频。远程地址用 file_url，由 Facebook 自己拉取；本地文件走 multipart source。
     *
     * @param string $accountId 账户 ID，可带或不带 act_ 前缀
     */
    public function uploadVideo(string $accountId, string $filePath, string $filename = ''): array
    {
        $accountId = $this->normalizeAccountId($accountId);
        $name = $filename !== '' ? $filename : $this->basenameFromPath($filePath, 'ad.mp4');

        if (preg_match('#^https?://#i', $filePath)) {
            return $this->client->postForm(
                "/act_{$accountId}/advideos",
                [
                    'file_url' => $filePath,
                    'name' => $name,
                ],
                [],
                'POST:/act_{ad_account_id}/advideos'
            );
        }

        if (! is_file($filePath)) {
            throw new InvalidArgumentException('上传视频不存在: ' . $filePath);
        }
        $bytes = file_get_contents($filePath);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('无法读取上传视频: ' . $filePath);
        }

        return $this->client->postMultipart(
            "/act_{$accountId}/advideos",
            ['name' => $name],
            [[
                'name' => 'source',
                'contents' => $bytes,
                'filename' => $name,
                'mime' => $this->guessVideoMime($name),
            ]],
            [],
            'POST:/act_{ad_account_id}/advideos'
        );
    }

    /**
     * 查询视频处理状态。status.video_status：processing / ready / error。
     */
    public function getVideo(string $videoId, array $fields = ['id', 'status', 'title', 'length']): array
    {
        return $this->client->get('/' . $videoId, [
            'fields' => implode(',', $fields),
        ], 'GET:/{video_id}');
    }

    /* ==================== 广告组 Ad Set ==================== */

    public function listAdsets(
        string $accountId,
        array $fields = self::DEFAULT_ADSET_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): array {
        $accountId = $this->normalizeAccountId($accountId);
        $adsets = $this->client->getAll(
            "/act_{$accountId}/adsets",
            $this->buildListParams($fields, $filters, $limit),
            'GET:/act_{ad_account_id}/adsets'
        );

        return Arr::uniqueBy($adsets, 'id');
    }

    public function iterateAdsets(
        string $accountId,
        array $fields = self::DEFAULT_ADSET_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): \Generator {
        $accountId = $this->normalizeAccountId($accountId);

        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/act_{$accountId}/adsets",
                $this->buildListParams($fields, $filters, $limit),
                'GET:/act_{ad_account_id}/adsets'
            ),
            'id'
        );
    }

    public function getAdset(string $adsetId, array $fields = self::DEFAULT_ADSET_FIELDS): array
    {
        return $this->client->get("/{$adsetId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{adset_id}');
    }

    /**
     * 创建广告组。
     *
     * @param array<string, mixed> $body 至少含 name、campaign_id、billing_event、optimization_goal、targeting 等
     */
    public function createAdset(string $accountId, array $body): array
    {
        $accountId = $this->normalizeAccountId($accountId);
        if ($body === []) {
            throw new InvalidArgumentException('创建广告组至少需要提供 body 参数');
        }

        return $this->client->post(
            "/act_{$accountId}/adsets",
            $body,
            [],
            'POST:/act_{ad_account_id}/adsets'
        );
    }

    public function updateAdset(string $adsetId, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        return $this->client->post("/{$adsetId}", $data, [], 'POST:/{adset_id}');
    }

    public function updateAdsetStatus(string $adsetId, string $status): array
    {
        return $this->updateStatus($adsetId, $status, 'POST:/{adset_id}');
    }

    public function pauseAdset(string $adsetId): array
    {
        return $this->updateAdsetStatus($adsetId, 'PAUSED');
    }

    public function activateAdset(string $adsetId): array
    {
        return $this->updateAdsetStatus($adsetId, 'ACTIVE');
    }

    public function deleteAdset(string $adsetId): array
    {
        return $this->client->delete("/{$adsetId}", [
            'status' => 'DELETED',
        ], 'DELETE:/{adset_id}');
    }

    public function archiveAdset(string $adsetId): array
    {
        return $this->updateAdsetStatus($adsetId, 'ARCHIVED');
    }

    /**
     * @return array<string, array{success: bool, data?: mixed, error?: string, code?: int}>
     */
    public function batchUpdateAdsetStatus(array $adsetIds, string $status): array
    {
        return $this->batchUpdateStatus($adsetIds, $status, 'POST:/?batch=adset_status');
    }

    /* ==================== 创意 Creative ==================== */

    public function listCreatives(
        string $accountId,
        array $fields = self::DEFAULT_CREATIVE_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): array {
        $accountId = $this->normalizeAccountId($accountId);
        $creatives = $this->client->getAll(
            "/act_{$accountId}/adcreatives",
            $this->buildListParams($fields, $filters, $limit),
            'GET:/act_{ad_account_id}/adcreatives'
        );

        return Arr::uniqueBy($creatives, 'id');
    }

    public function iterateCreatives(
        string $accountId,
        array $fields = self::DEFAULT_CREATIVE_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): \Generator {
        $accountId = $this->normalizeAccountId($accountId);

        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/act_{$accountId}/adcreatives",
                $this->buildListParams($fields, $filters, $limit),
                'GET:/act_{ad_account_id}/adcreatives'
            ),
            'id'
        );
    }

    public function getCreative(string $creativeId, array $fields = self::DEFAULT_CREATIVE_FIELDS): array
    {
        return $this->client->get("/{$creativeId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{creative_id}');
    }

    /**
     * 创建广告创意。
     *
     * @param array<string, mixed> $body 常用 object_story_spec / asset_feed_spec / degrees_of_freedom_spec
     */
    public function createCreative(string $accountId, array $body): array
    {
        $accountId = $this->normalizeAccountId($accountId);
        if ($body === []) {
            throw new InvalidArgumentException('创建创意至少需要提供 body 参数');
        }

        return $this->client->post(
            "/act_{$accountId}/adcreatives",
            $body,
            [],
            'POST:/act_{ad_account_id}/adcreatives'
        );
    }

    public function updateCreative(string $creativeId, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        return $this->client->post("/{$creativeId}", $data, [], 'POST:/{creative_id}');
    }

    public function deleteCreative(string $creativeId): array
    {
        return $this->client->delete("/{$creativeId}", [], 'DELETE:/{creative_id}');
    }

    /* ==================== 广告 Ad ==================== */

    public function listAds(
        string $accountId,
        array $fields = self::DEFAULT_AD_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): array {
        $accountId = $this->normalizeAccountId($accountId);
        $ads = $this->client->getAll(
            "/act_{$accountId}/ads",
            $this->buildListParams($fields, $filters, $limit),
            'GET:/act_{ad_account_id}/ads'
        );

        return Arr::uniqueBy($ads, 'id');
    }

    public function iterateAds(
        string $accountId,
        array $fields = self::DEFAULT_AD_FIELDS,
        array $filters = [],
        int $limit = 1000
    ): \Generator {
        $accountId = $this->normalizeAccountId($accountId);

        yield from Arr::uniqueGenerator(
            $this->client->paginate(
                "/act_{$accountId}/ads",
                $this->buildListParams($fields, $filters, $limit),
                'GET:/act_{ad_account_id}/ads'
            ),
            'id'
        );
    }

    public function getAd(string $adId, array $fields = self::DEFAULT_AD_FIELDS): array
    {
        return $this->client->get("/{$adId}", [
            'fields' => implode(',', $fields),
        ], 'GET:/{ad_id}');
    }

    /**
     * 创建广告。
     *
     * @param array<string, mixed> $body 至少含 name、adset_id、creative（如 ['creative_id' => '...']）
     */
    public function createAd(string $accountId, array $body): array
    {
        $accountId = $this->normalizeAccountId($accountId);
        if ($body === []) {
            throw new InvalidArgumentException('创建广告至少需要提供 body 参数');
        }

        return $this->client->post(
            "/act_{$accountId}/ads",
            $body,
            [],
            'POST:/act_{ad_account_id}/ads'
        );
    }

    public function updateAd(string $adId, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('至少需要提供一个要更新的字段');
        }

        return $this->client->post("/{$adId}", $data, [], 'POST:/{ad_id}');
    }

    public function updateAdStatus(string $adId, string $status): array
    {
        return $this->updateStatus($adId, $status, 'POST:/{ad_id}');
    }

    public function pauseAd(string $adId): array
    {
        return $this->updateAdStatus($adId, 'PAUSED');
    }

    public function activateAd(string $adId): array
    {
        return $this->updateAdStatus($adId, 'ACTIVE');
    }

    public function deleteAd(string $adId): array
    {
        return $this->client->delete("/{$adId}", [
            'status' => 'DELETED',
        ], 'DELETE:/{ad_id}');
    }

    public function archiveAd(string $adId): array
    {
        return $this->updateAdStatus($adId, 'ARCHIVED');
    }

    /**
     * @return array<string, array{success: bool, data?: mixed, error?: string, code?: int}>
     */
    public function batchUpdateAdStatus(array $adIds, string $status): array
    {
        return $this->batchUpdateStatus($adIds, $status, 'POST:/?batch=ad_status');
    }

    /* ==================== 内部工具 ==================== */

    protected function normalizeAccountId(string $accountId): string
    {
        return str_replace('act_', '', $accountId);
    }

    protected function updateStatus(string $objectId, string $status, string $apiInterface): array
    {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid status. Must be one of: ' . implode(', ', self::VALID_STATUSES)
            );
        }

        return $this->client->post("/{$objectId}", [
            'status' => $status,
        ], [], $apiInterface);
    }

    /**
     * @param list<string|int> $objectIds
     * @return array<string, array{success: bool, data?: mixed, error?: string, code?: int}>
     */
    protected function batchUpdateStatus(array $objectIds, string $status, string $apiInterface): array
    {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid status. Must be one of: ' . implode(', ', self::VALID_STATUSES)
            );
        }

        $objectIds = array_values(array_filter(array_map('strval', $objectIds)));
        if ($objectIds === []) {
            return [];
        }

        $requests = [];
        foreach ($objectIds as $objectId) {
            $requests[] = [
                'method' => 'POST',
                'relative_url' => $objectId,
                'body' => http_build_query(['status' => $status]),
            ];
        }

        $responses = $this->client->batch($requests, false, $apiInterface);
        $results = [];
        foreach ($objectIds as $index => $objectId) {
            $item = $responses[$index] ?? [
                'success' => false,
                'error' => 'Missing batch response',
                'body' => null,
                'code' => 0,
            ];
            if ($item['success']) {
                $results[$objectId] = [
                    'success' => true,
                    'data' => $item['body'],
                    'code' => $item['code'],
                ];
            } else {
                $results[$objectId] = [
                    'success' => false,
                    'error' => $item['error'] !== '' ? $item['error'] : 'Batch item failed',
                    'code' => $item['code'],
                    'data' => $item['body'],
                ];
            }
        }

        return $results;
    }

    /**
     * @param list<string> $fields
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
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
     * @return array{0: string, 1: string}
     */
    private function readImage(string $filePath): array
    {
        if (preg_match('#^https?://#i', $filePath)) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'follow_location' => 1,
                ],
            ]);
            $bytes = @file_get_contents($filePath, false, $context);
            if ($bytes === false || $bytes === '') {
                throw new InvalidArgumentException('无法下载广告图片: ' . $filePath);
            }

            return [$bytes, $this->basenameFromPath($filePath, 'ad.jpg')];
        }

        if (! is_file($filePath)) {
            throw new InvalidArgumentException('上传文件不存在: ' . $filePath);
        }
        $bytes = file_get_contents($filePath);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('无法读取上传文件: ' . $filePath);
        }

        return [$bytes, basename($filePath)];
    }

    private function guessMime(string $filePath, string $bytes): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => (str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg'),
        };
    }

    private function guessVideoMime(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            default => 'video/mp4',
        };
    }

    private function basenameFromPath(string $filePath, string $fallback): string
    {
        $path = preg_match('#^https?://#i', $filePath)
            ? (string) (parse_url($filePath, PHP_URL_PATH) ?: '')
            : $filePath;
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '/') {
            return $fallback;
        }

        return $name;
    }
}
