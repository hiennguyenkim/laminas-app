<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Entity\BorrowRecord;
use Library\Model\Table\BorrowTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class BorrowTableFactory
{
    public function __invoke(ContainerInterface $container): BorrowTable
    {
        $adapter            = $container->get(AdapterInterface::class);
        $resultSetPrototype = new ResultSet();
        $resultSetPrototype->setArrayObjectPrototype(new BorrowRecord());
        $tableGateway       = new TableGateway('borrow_records', $adapter, null, $resultSetPrototype);
        return new BorrowTable($tableGateway);
    }
}
