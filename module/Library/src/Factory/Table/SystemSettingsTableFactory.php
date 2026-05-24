<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\SystemSettingsTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class SystemSettingsTableFactory
{
    public function __invoke(ContainerInterface $container): SystemSettingsTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('system_settings', $adapter);
        return new SystemSettingsTable($tableGateway);
    }
}
