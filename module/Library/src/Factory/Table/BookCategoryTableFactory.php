<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\BookCategoryTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class BookCategoryTableFactory
{
    public function __invoke(ContainerInterface $container): BookCategoryTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('book_categories', $adapter);
        return new BookCategoryTable($tableGateway);
    }
}
