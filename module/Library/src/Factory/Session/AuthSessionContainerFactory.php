<?php

declare(strict_types=1);

namespace Library\Factory\Session;

use Library\Session\AuthSessionContainer;
use Laminas\Session\SessionManager;
use Psr\Container\ContainerInterface;

class AuthSessionContainerFactory
{
    public function __invoke(ContainerInterface $container): AuthSessionContainer
    {
        return new AuthSessionContainer($container->get(SessionManager::class));
    }
}
