<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\DashboardController;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Psr\Container\ContainerInterface;

class DashboardControllerFactory
{
    public function __invoke(ContainerInterface $container): DashboardController
    {
        return new DashboardController(
            $container->get(AuthSessionContainer::class),
            $container->get(BookTable::class),
            $container->get(BorrowTable::class),
            $container->get(UserTable::class),
        );
    }
}
