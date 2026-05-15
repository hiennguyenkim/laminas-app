<?php

declare(strict_types=1);

namespace Library\Factory\Controller\Api;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Library\Controller\Api\UserApiController;
use Library\Model\Table\UserTable;

class UserApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new UserApiController($container->get(UserTable::class));
    }
}
