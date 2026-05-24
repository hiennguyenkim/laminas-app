<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\TicketMessageTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class TicketMessageTableFactory
{
    public function __invoke(ContainerInterface $container): TicketMessageTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('ticket_messages', $adapter);
        return new TicketMessageTable($tableGateway);
    }
}
