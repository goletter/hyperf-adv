<?php

declare(strict_types=1);

namespace Goletter\Adv;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * Hyperf DI 工厂：注入 config/autoload/adv.php，并设为 AdvFactory 共享实例。
 */
class AdvFactoryFactory
{
    public function __invoke(ContainerInterface $container): AdvFactory
    {
        $config = [];
        if ($container->has(ConfigInterface::class)) {
            $config = (array) $container->get(ConfigInterface::class)->get('adv', []);
        }

        $factory = new AdvFactory($config);
        AdvFactory::setShared($factory);

        return $factory;
    }
}
