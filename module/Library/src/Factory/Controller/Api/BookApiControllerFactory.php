<?php

declare(strict_types=1);

namespace Library\Factory\Controller\Api;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Library\Controller\Api\BookApiController;
use Library\Model\Table\BookTable;

class BookApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new BookApiController($container->get(BookTable::class));
    }
}
