<?php

declare(strict_types=1);

namespace Library\Factory\Controller\Api;

use Library\Controller\Api\PaymentApiController;
use Library\Model\Table\PaymentSessionTable;
use Library\Service\GmailService;
use Library\Model\Table\SystemSettingsTable;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class PaymentApiControllerFactory
{
    public function __invoke(ContainerInterface $container): PaymentApiController
    {
        return new PaymentApiController(
            $container->get(PaymentSessionTable::class),
            $container->get(GmailService::class),
            $container->get(SystemSettingsTable::class),
            $container->get(AdapterInterface::class)
        );
    }
}
