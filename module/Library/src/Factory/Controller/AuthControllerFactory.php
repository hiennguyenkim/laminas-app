<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\AuthController;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Form\FormElementManager;
use Laminas\Session\SessionManager;
use Psr\Container\ContainerInterface;

class AuthControllerFactory
{
    public function __invoke(ContainerInterface $container): AuthController
    {
        return new AuthController(
            $container->get(AuthSessionContainer::class),
            $container->get(UserTable::class),
            $container->get(FormElementManager::class),
            $container->get(SessionManager::class),
        );
    }
}
