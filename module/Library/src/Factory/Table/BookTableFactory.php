<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Entity\Book;
use Library\Model\Table\BookTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class BookTableFactory
{
    public function __invoke(ContainerInterface $container): BookTable
    {
        $adapter       = $container->get(AdapterInterface::class);
        $resultSetPrototype = new ResultSet();
        $resultSetPrototype->setArrayObjectPrototype(new Book());
        $tableGateway  = new TableGateway('books', $adapter, null, $resultSetPrototype);
        return new BookTable($tableGateway);
    }
}
