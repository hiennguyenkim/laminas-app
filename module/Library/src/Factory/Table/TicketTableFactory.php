<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\TicketTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class TicketTableFactory
{
    public function __invoke(ContainerInterface $container): TicketTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('support_tickets', $adapter);
        return new TicketTable($tableGateway);
    }
}
