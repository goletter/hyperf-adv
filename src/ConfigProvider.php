<?php

declare(strict_types=1);

namespace Goletter\Adv;

class ConfigProvider
{
    public function __invoke(): array
    {
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
                    'description' => 'The config for adv component.',
                    'source' => __DIR__ . '/../publish/adv.php',
                    'destination' => BASE_PATH . '/config/autoload/adv.php',
                ],
            ],
        ];
    }
}
