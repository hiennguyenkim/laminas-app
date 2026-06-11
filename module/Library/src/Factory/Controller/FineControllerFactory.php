<?php

declare(strict_types=1);

namespace Library\Factory\Controller;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Library\Controller\FineController;
use Library\Session\AuthSessionContainer;
use Library\Model\Table\UserTable;
use Library\Service\MailService;
use Laminas\Db\Adapter\AdapterInterface;

class FineControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): FineController
    {
        return new FineController(
            $container->get(AuthSessionContainer::class),
            $container->get(UserTable::class),
            $container->get(AdapterInterface::class),
            $container->has(MailService::class) ? $container->get(MailService::class) : null
        );
    }
}
