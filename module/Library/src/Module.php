<?php

declare(strict_types=1);

namespace Library;

use Laminas\Mvc\MvcEvent;
use Laminas\Session\Container as SessionContainer;
use Laminas\Session\SessionManager;

class Module
{
    /**
     * @return array<array-key, mixed>
     */
    public function getConfig(): array
    {
        /** @var array<array-key, mixed>|mixed $config */
        $config = include __DIR__ . '/../config/module.config.php';

        return is_array($config) ? $config : [];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function onBootstrap(MvcEvent $event): void
    {
        $container = $event->getApplication()->getServiceManager();

        if (! $container->has(SessionManager::class)) {
            return;
        }

        $sessionManager = $container->get(SessionManager::class);
        SessionContainer::setDefaultManager($sessionManager);
        $sessionManager->start();
    }
}
