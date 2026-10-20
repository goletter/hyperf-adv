<?php

declare(strict_types=1);

namespace Goletter\Adv;

class ConfigProvider
{
    public function __invoke(): array
    {
        // 确保内置 / 已安装平台在容器启动时完成注册
        AdvFactory::boot();

        return [
            'dependencies' => [
                AdvFactory::class => AdvFactoryFactory::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => '多平台广告 SDK 配置（facebook / tiktok / google）。',
                    'source' => __DIR__ . '/../publish/adv.php',
                    'destination' => BASE_PATH . '/config/autoload/adv.php',
                ],
            ],
        ];
    }
}
