<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class TicketMessageTable
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

    public function insertMessage(int $ticketId, int $senderId, string $senderRole, string $message): void
    {
        $sql = "INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, ?, ?, NOW())";
        $this->getAdapter()->query($sql)->execute([$ticketId, $senderId, $senderRole, $message]);
    }

    public function fetchMessages(int $ticketId): array
    {
        $msgSql = "SELECT m.*, u.full_name, u.role FROM ticket_messages m JOIN users u ON m.sender_id = u.user_id WHERE m.ticket_id = ? ORDER BY m.sent_at ASC";
        $results = $this->getAdapter()->query($msgSql)->execute([$ticketId]);
        return iterator_to_array($results);
    }
}

