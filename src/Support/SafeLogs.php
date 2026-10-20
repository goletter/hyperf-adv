<?php

declare(strict_types=1);

namespace Goletter\Adv\Support;

/**
 * 可选对接 goletter/hyperf-utils 的 logging()；未安装时静默跳过。
 */
trait SafeLogs
{
    protected function safeLog(mixed $data, string $message = '', string $channel = ''): void
    {
        if (\function_exists('Goletter\\Utils\\logging')) {
            \Goletter\Utils\logging($data, $message, $channel);
        }
    }
}
