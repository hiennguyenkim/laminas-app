<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class PublicChatTable
{
    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    private function getAdapter(): \Laminas\Db\Adapter\Adapter
    {
        /** @var \Laminas\Db\Adapter\Adapter $adapter */
        $adapter = $this->tableGateway->getAdapter();
        return $adapter;
    }

    public function deleteMessage(int $messageId): void
    {
        $sql = "DELETE FROM public_chats WHERE id = ?";
        $this->getAdapter()->query($sql, [$messageId]);
    }

    public function deleteUserMessage(int $messageId, int $userId): void
    {
        $sql = "DELETE FROM public_chats WHERE id = ? AND user_id = ?";
        $this->getAdapter()->query($sql, [$messageId, $userId]);
    }

    public function unpinAll(): void
    {
        $this->getAdapter()->query("UPDATE public_chats SET is_pinned = 0", []);
    }

    public function pinMessage(int $messageId): void
    {
        $this->getAdapter()->query("UPDATE public_chats SET is_pinned = 1 WHERE id = ?", [$messageId]);
    }

    public function getReactions(int $messageId): ?string
    {
        $sql = "SELECT reactions FROM public_chats WHERE id = ?";
        $stmt = $this->getAdapter()->query($sql);
        $row = $stmt->execute([$messageId])->current();
        if (!$row) {
            return null;
        }
        return $row['reactions'] !== null ? (string)$row['reactions'] : '';
    }

    public function updateReactions(int $messageId, string $reactions): void
    {
        $sql = "UPDATE public_chats SET reactions = ? WHERE id = ?";
        $this->getAdapter()->query($sql, [$reactions, $messageId]);
    }

    public function insertMessage(int $userId, string $message): void
    {
        $sql = "INSERT INTO public_chats (user_id, message, created_at) VALUES (?, ?, NOW())";
        $this->getAdapter()->query($sql, [$userId, $message]);
    }

    public function getPinnedMessage(): ?array
    {
        $pinnedSql = "SELECT c.*, COALESCE(NULLIF(u.nickname, ''), u.full_name, CONCAT('Độc giả #', u.user_id)) AS nickname, u.role, u.avatar_url 
                      FROM public_chats c 
                      JOIN users u ON c.user_id = u.user_id 
                      WHERE c.is_pinned = 1 
                      LIMIT 1";
        $row = $this->getAdapter()->query($pinnedSql)->execute()->current();
        return $row ? (array) $row : null;
    }

    public function fetchRecentMessages(int $limit = 50): array
    {
        $sql = "SELECT * FROM (
                    SELECT c.*, COALESCE(NULLIF(u.nickname, ''), u.full_name, CONCAT('Độc giả #', u.user_id)) AS nickname, u.role, u.avatar_url 
                    FROM public_chats c 
                    JOIN users u ON c.user_id = u.user_id 
                    ORDER BY c.id DESC 
                    LIMIT ?
                ) sub
                ORDER BY sub.id ASC";
        $results = $this->getAdapter()->query($sql)->execute([$limit]);
        return iterator_to_array($results);
    }

    public function fetchMessagesBefore(int $beforeId, int $limit = 50): array
    {
        $sql = "SELECT * FROM (
                    SELECT c.*, COALESCE(NULLIF(u.nickname, ''), u.full_name, CONCAT('Độc giả #', u.user_id)) AS nickname, u.role, u.avatar_url 
                    FROM public_chats c 
                    JOIN users u ON c.user_id = u.user_id 
                    WHERE c.id < ?
                    ORDER BY c.id DESC 
                    LIMIT ?
                ) sub
                ORDER BY sub.id ASC";
        $results = $this->getAdapter()->query($sql)->execute([$beforeId, $limit]);
        return iterator_to_array($results);
    }
}
