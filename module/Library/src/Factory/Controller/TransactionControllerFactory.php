<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\TransactionController;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Library\Service\CirculationService;
use Laminas\Form\FormElementManager;
use Psr\Container\ContainerInterface;

class TransactionControllerFactory
{
    public function __invoke(ContainerInterface $container): TransactionController
    {
        return new TransactionController(
            $container->get(AuthSessionContainer::class),
            $container->get(BorrowTable::class),
            $container->get(BookTable::class),
            $container->get(UserTable::class),
            $container->get(CirculationService::class),
            $container->get(FormElementManager::class),
        );
    }
}
