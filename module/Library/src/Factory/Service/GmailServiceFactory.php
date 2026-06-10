<?php

declare(strict_types=1);

namespace Library\Factory\Service;

use Library\Service\GmailService;
use Library\Model\Table\PaymentSessionTable;
use Library\Model\Table\UserTable;
use Library\Model\Table\SystemSettingsTable;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class GmailServiceFactory
{
    public function __invoke(ContainerInterface $container): GmailService
    {
        return new GmailService(
            $container->get(PaymentSessionTable::class),
            $container->get(UserTable::class),
            $container->get(SystemSettingsTable::class),
            $container->get(AdapterInterface::class),
            $container->get(AuthSessionContainer::class)
        );
    }
}
