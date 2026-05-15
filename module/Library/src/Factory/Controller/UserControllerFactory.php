<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\UserController;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Form\FormElementManager;
use Psr\Container\ContainerInterface;

class UserControllerFactory
{
    public function __invoke(ContainerInterface $container): UserController
    {
        return new UserController(
            $container->get(AuthSessionContainer::class),
            $container->get(BorrowTable::class),
            $container->get(UserTable::class),
            $container->get(FormElementManager::class),
        );
    }
}
