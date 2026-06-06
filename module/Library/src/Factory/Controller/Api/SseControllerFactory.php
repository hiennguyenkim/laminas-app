<?php

declare(strict_types=1);

namespace Library\Factory\Controller\Api;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Library\Controller\Api\SseController;
use Library\Session\AuthSessionContainer;
use Library\Model\Table\NotificationTable;
use Library\Model\Table\PublicChatTable;

class SseControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new SseController(
            $container->get(AuthSessionContainer::class),
            $container->get(NotificationTable::class),
            $container->get(PublicChatTable::class)
        );
    }
}
