<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\BookController;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\AnnouncementTable;
use Library\Model\Table\BookReviewTable;
use Library\Model\Table\BookCategoryTable;
use Library\Session\AuthSessionContainer;
use Laminas\Form\FormElementManager;
use Psr\Container\ContainerInterface;

class BookControllerFactory
{
    public function __invoke(ContainerInterface $container): BookController
    {
        return new BookController(
            $container->get(AuthSessionContainer::class),
            $container->get(BookTable::class),
            $container->get(BorrowTable::class),
            $container->get(FormElementManager::class),
            $container->get(AnnouncementTable::class),
            $container->get(BookReviewTable::class),
            $container->get(BookCategoryTable::class)
        );
    }
}
