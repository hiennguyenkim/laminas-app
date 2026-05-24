<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\PublicChatTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class PublicChatTableFactory
{
    public function __invoke(ContainerInterface $container): PublicChatTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('public_chats', $adapter);
        return new PublicChatTable($tableGateway);
    }
}
