<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Entity\User;
use Library\Model\Table\UserTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class UserTableFactory
{
    public function __invoke(ContainerInterface $container): UserTable
    {
        $adapter            = $container->get(AdapterInterface::class);
        $resultSetPrototype = new ResultSet();
        /** @var \ArrayObject $prototype */
        $prototype = new User();
        $resultSetPrototype->setArrayObjectPrototype($prototype);
        $tableGateway       = new TableGateway('users', $adapter, null, $resultSetPrototype);
        return new UserTable($tableGateway);
    }
}
