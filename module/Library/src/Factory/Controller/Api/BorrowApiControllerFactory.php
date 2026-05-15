<?php

declare(strict_types=1);

namespace Library\Factory\Controller\Api;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Library\Controller\Api\BorrowApiController;
use Library\Model\Table\BorrowTable;

class BorrowApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new BorrowApiController($container->get(BorrowTable::class));
    }
}
