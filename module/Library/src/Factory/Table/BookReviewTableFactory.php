<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\BookReviewTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class BookReviewTableFactory
{
    public function __invoke(ContainerInterface $container): BookReviewTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('book_reviews', $adapter);
        return new BookReviewTable($tableGateway);
    }
}
