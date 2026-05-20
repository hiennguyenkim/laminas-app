<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\BookImportController;
use Library\Model\Table\BookTable;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class BookImportControllerFactory
{
    public function __invoke(ContainerInterface $container): BookImportController
    {
        return new BookImportController(
            $container->get(AuthSessionContainer::class),
            $container->get(BookTable::class),
            $container->get(AdapterInterface::class)
        );
    }
}
