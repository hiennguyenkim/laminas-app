<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\NotificationTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class NotificationTableFactory
{
    public function __invoke(ContainerInterface $container): NotificationTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('notifications', $adapter);
        return new NotificationTable($tableGateway);
    }
}
