<?php

declare(strict_types=1);

namespace Library\Factory\Service;

use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Library\Service\GeminiService;

class GeminiServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null): GeminiService
    {
        $config = $container->get('config');
        $apiKey = $config['gemini']['api_key'] ?? '';
        $adapter = $container->get(AdapterInterface::class);
        
        return new GeminiService($apiKey, $adapter);
    }
}
