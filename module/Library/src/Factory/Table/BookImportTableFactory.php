<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\BookImportTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class BookImportTableFactory
{
    public function __invoke(ContainerInterface $container): BookImportTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('book_imports', $adapter);
        return new BookImportTable($tableGateway);
    }
}
