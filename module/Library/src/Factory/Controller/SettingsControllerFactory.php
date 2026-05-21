<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\SettingsController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class SettingsControllerFactory
{
    public function __invoke(ContainerInterface $container): SettingsController
    {
        return new SettingsController(
            $container->get(AuthSessionContainer::class),
            $container->get(AdapterInterface::class)
        );
    }
}
