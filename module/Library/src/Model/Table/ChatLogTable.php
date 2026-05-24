<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class ChatLogTable
{
    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    private function getAdapter(): AdapterInterface
    {
        return $this->tableGateway->getAdapter();
    }

    public function insertLog(?int $userId, string $message, string $response): void
    {
        $sql = 'INSERT INTO chat_logs (user_id, message, response, created_at) VALUES (?, ?, ?, NOW())';
        $this->getAdapter()->query($sql)->execute([$userId, $message, $response]);
    }
}

