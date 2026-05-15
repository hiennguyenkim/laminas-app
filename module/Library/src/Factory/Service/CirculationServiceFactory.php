<?php

declare(strict_types=1);

namespace Library\Factory\Service;

use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Service\CirculationService;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class CirculationServiceFactory
{
    public function __invoke(ContainerInterface $container): CirculationService
    {
        return new CirculationService(
            $container->get(AdapterInterface::class),
            $container->get(BookTable::class),
            $container->get(BorrowTable::class),
            $container->get(UserTable::class),
        );
    }
}
