<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\TicketController;
use Library\Model\Table\TicketTable;
use Library\Model\Table\TicketMessageTable;
use Library\Model\Table\NotificationTable;
use Library\Session\AuthSessionContainer;
use Psr\Container\ContainerInterface;

class TicketControllerFactory
{
    public function __invoke(ContainerInterface $container): TicketController
    {
        return new TicketController(
            $container->get(AuthSessionContainer::class),
            $container->get(TicketTable::class),
            $container->get(TicketMessageTable::class),
            $container->get(NotificationTable::class)
        );
    }
}
