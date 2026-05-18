<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\TicketController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class TicketControllerFactory
{
    public function __invoke(ContainerInterface $container): TicketController
    {
        return new TicketController(
            $container->get(AuthSessionContainer::class),
            $container->get(AdapterInterface::class)
        );
    }
}
