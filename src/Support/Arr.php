<?php

declare(strict_types=1);

namespace Goletter\Adv\Support;

final class Arr
{
    /**
     * 基于指定字段去重（保留首次出现）
     */
    public static function uniqueBy(array $items, string $field = 'id'): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = $item[$field] ?? null;
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * 流式去重生成器
     *
     * @param iterable<mixed> $items
     * @return \Generator<int, array>
     */
    public static function uniqueGenerator(iterable $items, string $field = 'id'): \Generator
    {
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = $item[$field] ?? null;
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            yield $item;
        }
    }
}
