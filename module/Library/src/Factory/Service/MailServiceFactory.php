<?php

declare(strict_types=1);

namespace Library\Factory\Service;

use Library\Service\MailService;
use Laminas\Db\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

class MailServiceFactory
{
    public function __invoke(ContainerInterface $container): MailService
    {
        return new MailService(
            $container->get(AdapterInterface::class)
        );
    }
}
