<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Table\ChatLogTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class ChatLogTableFactory
{
    public function __invoke(ContainerInterface $container): ChatLogTable
    {
        $adapter = $container->get(AdapterInterface::class);
        $tableGateway = new TableGateway('chat_logs', $adapter);
        return new ChatLogTable($tableGateway);
    }
}
