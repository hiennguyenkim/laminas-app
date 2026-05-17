<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\ProfileController;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Psr\Container\ContainerInterface;

class ProfileControllerFactory
{
    public function __invoke(ContainerInterface $container): ProfileController
    {
        return new ProfileController(
            $container->get(AuthSessionContainer::class),
            $container->get(UserTable::class),
            $container->get(BorrowTable::class)
        );
    }
}
