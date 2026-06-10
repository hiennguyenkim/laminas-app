<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Library\Controller\SettingsController;
use Library\Model\Table\SystemSettingsTable;
use Library\Model\Table\BookCategoryTable;
use Library\Model\Table\BookTable;
use Library\Service\GmailService;
use Library\Session\AuthSessionContainer;
use Psr\Container\ContainerInterface;

class SettingsControllerFactory
{
    public function __invoke(ContainerInterface $container): SettingsController
    {
        return new SettingsController(
            $container->get(AuthSessionContainer::class),
            $container->get(SystemSettingsTable::class),
            $container->get(BookCategoryTable::class),
            $container->get(BookTable::class),
            $container->get(GmailService::class)
        );
    }
}
