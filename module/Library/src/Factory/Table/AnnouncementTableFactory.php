<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\AnnouncementTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class AnnouncementTableFactory
{
    public function __invoke(ContainerInterface $container): AnnouncementTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('announcements', $adapter);
        return new AnnouncementTable($tableGateway);
    }
}
