<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\AnnouncementController;
use Library\Session\AuthSessionContainer;
use Psr\Container\ContainerInterface;

class AnnouncementControllerFactory
{
    public function __invoke(ContainerInterface $container): AnnouncementController
    {
        return new AnnouncementController(
            $container->get(AuthSessionContainer::class),
            $container->get(\Laminas\Db\Adapter\AdapterInterface::class)
        );
    }
}
