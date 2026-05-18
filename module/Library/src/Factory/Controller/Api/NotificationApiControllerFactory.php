<?php

declare(strict_types=1);

namespace Library\Factory\Controller\Api;

use Library\Controller\Api\NotificationApiController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class NotificationApiControllerFactory
{
    public function __invoke(ContainerInterface $container): NotificationApiController
    {
        return new NotificationApiController(
            $container->get(AuthSessionContainer::class),
            $container->get(AdapterInterface::class)
        );
    }
}
