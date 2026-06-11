<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

/**
 * @psalm-suppress UndefinedInterfaceMethod
 * @psalm-suppress PossiblyUndefinedMethod
 * @psalm-suppress MixedMethodCall
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgument
 */
class TicketTable
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

    public function countTickets(array $filters, ?int $userId = null, bool $isAdmin = false): int
    {
        $whereClause = '';
        $params = [];
        $this->buildWhereClause($filters, $userId, $whereClause, $params, $isAdmin);

        $sql = "SELECT COUNT(*) as cnt FROM support_tickets t JOIN users u ON t.user_id = u.user_id $whereClause";
        $row = $this->getAdapter()->query($sql)->execute($params)->current();
        return (int) (($row['cnt']) ?? 0);
    }

    public function fetchTickets(array $filters, ?int $userId, int $page, int $perPage, bool $isAdmin = false): array
    {
        $whereClause = '';
        $params = [];
        $this->buildWhereClause($filters, $userId, $whereClause, $params, $isAdmin);

        $direction = strtoupper($filters['direction'] ?? 'DESC');
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }
        $sort = $filters['sort'] ?? '';
        
        $allowedSorts = [
            'id' => 't.id',
            'title' => 't.title',
            'author' => 'u.full_name',
            'updated_at' => 't.updated_at',
            'status' => 't.status',
        ];

        $orderBy = 't.status ASC, t.updated_at DESC';
        if (array_key_exists($sort, $allowedSorts)) {
            $orderBy = $allowedSorts[$sort] . ' ' . $direction;
        }

        $sql = "SELECT t.*, u.full_name as author_name FROM support_tickets t JOIN users u ON t.user_id = u.user_id $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?";
        
        $offset = ($page - 1) * $perPage;
        $fetchParams = $params;
        $fetchParams[] = $perPage;
        $fetchParams[] = $offset;

        $results = $this->getAdapter()->query($sql)->execute($fetchParams);
        return iterator_to_array($results);
    }

    public function getTicket(int $id): ?array
    {
        $sql = "SELECT t.*, u.full_name as author_name, u.email as author_email FROM support_tickets t JOIN users u ON t.user_id = u.user_id WHERE t.id = ?";
        $row = $this->getAdapter()->query($sql)->execute([$id])->current();
        return $row ? (array) $row : null;
    }

    public function createTicket(int $userId, string $title, string $content): int
    {
        $sql = "INSERT INTO support_tickets (user_id, title, description, status, created_at, updated_at) VALUES (?, ?, ?, 'open', NOW(), NOW())";
        $this->getAdapter()->query($sql)->execute([$userId, $title, $content]);
        return (int) $this->getAdapter()->getDriver()->getLastGeneratedValue();
    }

    public function closeTicket(int $id): void
    {
        $sql = "UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = ?";
        $this->getAdapter()->query($sql)->execute([$id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $sql = "UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?";
        $this->getAdapter()->query($sql)->execute([$status, $id]);
    }

    private function buildWhereClause(array $filters, ?int $userId, string &$whereClause, array &$params, bool $isAdmin = false): void
    {
        $where = [];
        if (!$isAdmin && $userId !== null) {
            $where[] = "t.user_id = ?";
            $params[] = $userId;
        }

        $status = $filters['status'] ?? '';
        if ($status === 'unanswered') {
            $where[] = "t.status = 'open'";
        } elseif ($status === 'answered') {
            $where[] = "t.status IN ('in_progress', 'closed')";
        }

        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            if ($isAdmin) {
                $where[] = "(t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            } else {
                $where[] = "(t.title LIKE ? OR t.description LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
        }

        if ($where) {
            $whereClause = 'WHERE ' . implode(' AND ', $where);
        }
    }
}

