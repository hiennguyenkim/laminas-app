<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\HomeController;
use Library\Session\AuthSessionContainer;
use Psr\Container\ContainerInterface;

class HomeControllerFactory
{
    public function __invoke(ContainerInterface $container): HomeController
    {
        return new HomeController($container->get(AuthSessionContainer::class));
    }
}
