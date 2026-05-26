<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class AnnouncementTable
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

    public function getGlobalCounts(): array
    {
        $sql = "SELECT 
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN start_date IS NOT NULL AND start_date > CURDATE() THEN 1 ELSE 0 END) AS upcoming_count,
                    SUM(CASE WHEN end_date IS NOT NULL AND end_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
                    SUM(CASE WHEN is_active = 0 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) AS hidden_count
                 FROM announcements";
        $result = $this->getAdapter()->query($sql)->execute()->current();
        return $result ? (array) $result : [];
    }

    public function fetchAnnouncements(array $filters, int $page, int $perPage, string $sort, string $direction): array
    {
        $whereClause = '';
        $params = [];
        $this->buildWhereClause($filters, $whereClause, $params);

        $orderBy = 'a.created_at DESC';
        $allowedSortColumns = ['id', 'title', 'type', 'is_active', 'start_date', 'end_date', 'created_at'];
        if (in_array($sort, $allowedSortColumns, true)) {
            $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
            if ($sort === 'is_active') {
                $orderBy = "a.is_active $direction, a.created_at DESC";
            } else {
                $orderBy = "a.$sort $direction";
            }
        }

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT a.*, COALESCE(NULLIF(u.nickname, ''), u.full_name) AS creator_name 
                FROM announcements a 
                LEFT JOIN users u ON a.created_by = u.user_id 
                $whereClause 
                ORDER BY $orderBy 
                LIMIT $perPage OFFSET $offset";

        $results = $this->getAdapter()->query($sql)->execute($params);
        return iterator_to_array($results);
    }

    public function countFiltered(array $filters): int
    {
        $whereClause = '';
        $params = [];
        $this->buildWhereClause($filters, $whereClause, $params);

        $sql = "SELECT COUNT(*) as cnt FROM announcements a $whereClause";
        $row = $this->getAdapter()->query($sql)->execute($params)->current();
        return (int) (($row['cnt']) ?? 0);
    }

    public function getTypeCounts(array $filters): array
    {
        $whereClause = '';
        $params = [];
        $this->buildWhereClause($filters, $whereClause, $params);

        $sql = "SELECT type, COUNT(*) as cnt FROM announcements a $whereClause GROUP BY type";
        $results = $this->getAdapter()->query($sql)->execute($params);
        return iterator_to_array($results);
    }

    public function getAnnouncement(int $id): ?array
    {
        $sql = "SELECT a.*, COALESCE(NULLIF(u.nickname, ''), u.full_name) AS creator_name 
                FROM announcements a 
                LEFT JOIN users u ON a.created_by = u.user_id 
                WHERE a.id = ? LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute([$id])->current();
        return $row ? (array) $row : null;
    }

    public function insertAnnouncement(array $data): void
    {
        $sql = "INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $this->getAdapter()->query($sql)->execute([
            $data['title'],
            $data['content'],
            $data['type'],
            $data['start_date'],
            $data['end_date'],
            $data['is_active'],
            $data['created_by']
        ]);
    }

    public function updateAnnouncement(int $id, array $data): void
    {
        $updates = [];
        $params = [];
        if (isset($data['title'])) { $updates[] = "title = ?"; $params[] = $data['title']; }
        if (isset($data['content'])) { $updates[] = "content = ?"; $params[] = $data['content']; }
        if (isset($data['type'])) { $updates[] = "type = ?"; $params[] = $data['type']; }
        if (isset($data['is_active'])) { $updates[] = "is_active = ?"; $params[] = $data['is_active']; }
        if (array_key_exists('start_date', $data)) { $updates[] = "start_date = ?"; $params[] = $data['start_date']; }
        if (array_key_exists('end_date', $data)) { $updates[] = "end_date = ?"; $params[] = $data['end_date']; }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE announcements SET " . implode(', ', $updates) . " WHERE id = ?";
        $this->getAdapter()->query($sql)->execute($params);
    }

    public function deleteAnnouncement(int $id): void
    {
        $this->tableGateway->delete(['id' => $id]);
    }

    public function fetchActiveForSidebar(int $limit = 3): array
    {
        $sql = 'SELECT * FROM announcements 
                WHERE is_active = 1 
                  AND (start_date IS NULL OR start_date <= CURDATE()) 
                  AND (end_date IS NULL OR end_date >= CURDATE()) 
                ORDER BY created_at DESC LIMIT ?';
        $results = $this->getAdapter()->query($sql)->execute([$limit]);
        return iterator_to_array($results);
    }

    public function fetchActiveAnnouncements(string $typeFilter = 'all', string $searchQuery = ''): array
    {
        $sql = 'SELECT * FROM announcements WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())';
        $params = [];

        if ($typeFilter !== 'all') {
            $sql .= ' AND type = ?';
            $params[] = $typeFilter;
        }

        if ($searchQuery !== '') {
            $sql .= ' AND (title LIKE ? OR content LIKE ?)';
            $params[] = '%' . $searchQuery . '%';
            $params[] = '%' . $searchQuery . '%';
        }

        $sql .= ' ORDER BY created_at DESC';
        $results = $this->getAdapter()->query($sql)->execute($params);
        return iterator_to_array($results);
    }

    private function buildWhereClause(array $filters, string &$whereClause, array &$params): void
    {
        $where = [];
        $search = $filters['search'] ?? '';
        $type = $filters['type'] ?? '';
        $status = $filters['status'] ?? '';

        $allowedTypes = ['event', 'contest', 'holiday', 'general'];
        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $where[] = "a.type = ?";
            $params[] = $type;
        }

        if ($status === 'active') {
            $where[] = "a.is_active = 1 AND (a.start_date IS NULL OR a.start_date <= CURDATE()) AND (a.end_date IS NULL OR a.end_date >= CURDATE())";
        } elseif ($status === 'upcoming') {
            $where[] = "a.start_date IS NOT NULL AND a.start_date > CURDATE()";
        } elseif ($status === 'expired') {
            $where[] = "a.end_date IS NOT NULL AND a.end_date < CURDATE()";
        } elseif ($status === 'hidden') {
            $where[] = "a.is_active = 0 AND (a.start_date IS NULL OR a.start_date <= CURDATE()) AND (a.end_date IS NULL OR a.end_date >= CURDATE())";
        }

        if ($search !== '') {
            $where[] = "(a.title LIKE ? OR a.content LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if ($where) {
            $whereClause = 'WHERE ' . implode(' AND ', $where);
        }
    }
}
