<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Library\Model\Entity\User;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;

class UserTable
{
    private const PK = 'user_id';

    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function getByUsername(string $username): ?User
    {
        $rowset = $this->tableGateway->select(['username' => $username]);
        return $this->firstUserFromRowset($rowset);
    }

    public function getByGoogleId(string $googleId): ?User
    {
        $rowset = $this->tableGateway->select(['google_id' => $googleId]);
        return $this->firstUserFromRowset($rowset);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getByEmail(string $email): ?User
    {
        $rowset = $this->tableGateway->select(['email' => $email]);
        return $this->firstUserFromRowset($rowset);
    }

    public function getUser(int $id): User
    {
        $rowset = $this->tableGateway->select([self::PK => $id]);
        $row = $this->firstUserFromRowset($rowset);

        if (! $row instanceof User) {
            throw new \RuntimeException(sprintf('Không tìm thấy tài khoản có ID %d.', $id));
        }

        return $row;
    }

    public function fetchAll(array $filters = [], string $sort = 'id', string $direction = 'ASC'): \Laminas\Db\ResultSet\ResultSetInterface
    {
        return $this->tableGateway->select(function (Select $select) use ($filters, $sort, $direction): void {
            $select->columns([
                'user_id',
                'username',
                'email',
                'password',
                'full_name',
                'role',
                'created_at',
                'nickname',
                'date_of_birth',
                'avatar_url',
                'account_status',
                'lock_reason',
                'locked_at',
                'locked_until',
                'phone',
                'borrow_limit',
                'last_returned_at' => new Expression(
                    '(SELECT MAX(br.returned_at) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND br.returned_at IS NOT NULL)'
                ),
                'borrowCount' => new Expression(
                    '(SELECT COUNT(*) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND (br.status IN (\'borrowed\', \'overdue\') '
                    . 'OR (br.status = \'borrowed\' AND br.return_date < CURDATE())))'
                ),
                'overdueCount' => new Expression(
                    '(SELECT COUNT(*) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND (br.status = \'overdue\' '
                    . 'OR (br.status = \'borrowed\' AND br.return_date < CURDATE()) '
                    . 'OR (br.status = \'returned\' AND br.returned_at IS NOT NULL AND DATE(br.returned_at) > br.return_date)))'
                ),
                'totalBorrowedCount' => new Expression(
                    '(SELECT COUNT(*) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND br.status IN (\'borrowed\', \'returned\', \'overdue\'))'
                ),
            ]);

            $this->applyFilters($select, $filters);

            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sortField = match ($sort) {
                'username'  => 'users.username',
                'full_name' => 'users.full_name',
                'email'     => 'users.email',
                'role'      => 'users.role',
                'status'    => 'users.account_status',
                'created'   => 'users.created_at',
                'borrow_count' => 'borrowCount',
                'overdue_count' => 'overdueCount',
                'total_borrowed' => 'totalBorrowedCount',
                default     => 'users.user_id',
            };

            $select->order($sortField . ' ' . $direction);
        });
    }

    public function fetchPage(
        array $filters,
        int $page,
        int $perPage,
        string $sort = 'id',
        string $direction = 'ASC'
    ): \Laminas\Db\ResultSet\ResultSetInterface {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);
        $offset = ($safePage - 1) * $safePerPage;

        return $this->tableGateway->select(function (Select $select) use ($filters, $safePerPage, $offset, $sort, $direction): void {
            $select->columns([
                'user_id',
                'username',
                'email',
                'password',
                'full_name',
                'role',
                'created_at',
                'nickname',
                'date_of_birth',
                'avatar_url',
                'account_status',
                'lock_reason',
                'locked_at',
                'locked_until',
                'phone',
                'borrow_limit',
                'last_returned_at' => new Expression(
                    '(SELECT MAX(br.returned_at) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND br.returned_at IS NOT NULL)'
                ),
                'borrowCount' => new Expression(
                    '(SELECT COUNT(*) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND (br.status IN (\'borrowed\', \'overdue\') '
                    . 'OR (br.status = \'borrowed\' AND br.return_date < CURDATE())))'
                ),
                'overdueCount' => new Expression(
                    '(SELECT COUNT(*) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND (br.status = \'overdue\' '
                    . 'OR (br.status = \'borrowed\' AND br.return_date < CURDATE()) '
                    . 'OR (br.status = \'returned\' AND br.returned_at IS NOT NULL AND DATE(br.returned_at) > br.return_date)))'
                ),
                'totalBorrowedCount' => new Expression(
                    '(SELECT COUNT(*) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND br.status IN (\'borrowed\', \'returned\', \'overdue\'))'
                ),
            ]);

            $this->applyFilters($select, $filters);

            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sortField = match ($sort) {
                'username'  => 'users.username',
                'full_name' => 'users.full_name',
                'email'     => 'users.email',
                'role'      => 'users.role',
                'status'    => 'users.account_status',
                'created'   => 'users.created_at',
                'borrow_count' => 'borrowCount',
                'overdue_count' => 'overdueCount',
                'total_borrowed' => 'totalBorrowedCount',
                default     => 'users.user_id',
            };

            $select->order($sortField . ' ' . $direction);
            $select->limit($safePerPage);
            $select->offset($offset);
        });
    }

    public function countFiltered(array $filters = []): int
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select();
        $select->columns(['c' => new Expression('COUNT(*)')]);

        $this->applyFilters($select, $filters);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    private function applyFilters(Select $select, array $filters): void
    {
        $searchValue = trim((string) ($filters['search'] ?? ''));
        if ($searchValue !== '') {
            $search = '%' . $searchValue . '%';
            $select->where(function (Where $where) use ($search): void {
                $where->nest()
                    ->like('full_name', $search)
                    ->or
                    ->like('username', $search)
                    ->or
                    ->like('email', $search)
                    ->unnest();
            });
        }

        $role = trim((string) ($filters['role'] ?? ''));
        if ($role !== '') {
            $select->where(['role' => $role]);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $select->where(['account_status' => $status]);
        }

        if (isset($filters['is_approved'])) {
            $select->where(['is_approved' => (int)$filters['is_approved']]);
        }
    }

    public function fetchStudentOptions(): array
    {
        return iterator_to_array($this->fetchAll(['role' => 'student']));
    }

    public function countAll(): int
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns(['c' => new Expression('COUNT(*)')]);
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function countByRole(string $role): int
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns(['c' => new Expression('COUNT(*)')])
            ->where(['role' => $role]);
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $rowset = $this->tableGateway->select(function (Select $select) use ($username, $excludeId): void {
            $select->columns([self::PK]);
            $select->where(['username' => $username]);

            if ($excludeId !== null) {
                $select->where->notEqualTo(self::PK, $excludeId);
            }

            $select->limit(1);
        });

        return $rowset->count() > 0;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $rowset = $this->tableGateway->select(function (Select $select) use ($email, $excludeId): void {
            $select->columns([self::PK]);
            $select->where(['email' => $email]);

            if ($excludeId !== null) {
                $select->where->notEqualTo(self::PK, $excludeId);
            }

            $select->limit(1);
        });

        return $rowset->count() > 0;
    }

    public function deleteUser(int $id): void
    {
        $this->tableGateway->delete([self::PK => $id]);
    }

    public function lockUser(int $id, string $reason = '', ?string $until = null, ?int $adminId = null): void
    {
        $updateData = [
            'account_status' => 'locked',
            'locked_at'      => new Expression('NOW()'),
        ];

        if ($until !== null) {
            // Chỉ cập nhật ngày mở khóa và lý do nếu hình phạt mới NẶNG HƠN hoặc NGANG BẰNG hình phạt cũ
            // Sử dụng câu lệnh SQL để so sánh và quyết định cập nhật lý do
            $updateData['locked_until'] = new Expression("GREATEST(IFNULL(locked_until, '0000-00-00'), ?)", [$until]);
            
            // Logic: Nếu ngày mới >= ngày cũ thì dùng lý do mới, nếu không giữ nguyên lý do cũ
            $updateData['lock_reason'] = new Expression(
                "CASE WHEN ? >= IFNULL(locked_until, '0000-00-00') THEN ? ELSE lock_reason END",
                [$until, $reason]
            );
        } else {
            $updateData['lock_reason'] = $reason;
            $updateData['locked_until'] = null;
        }

        $this->tableGateway->update($updateData, [self::PK => $id]);

        // Ghi nhật ký xử phạt (Hạng mục 4)
        try {
            $sql = "INSERT INTO penalty_logs (user_id, admin_id, reason, locked_until, created_at) VALUES (?, ?, ?, ?, NOW())";
            $this->tableGateway->getAdapter()->query($sql)->execute([$id, $adminId, $reason, $until]);
        } catch (\Throwable $e) {}
    }

    public function unlockUser(int $id): void
    {
        $this->tableGateway->update([
            'account_status' => 'active',
            'lock_reason'    => null,
            'locked_at'      => null,
            'locked_until'   => null,
        ], [self::PK => $id]);
    }

    public function clearNickname(int $id): void
    {
        $this->tableGateway->update(['nickname' => null], [self::PK => $id]);
    }

    public function clearAvatar(int $id): void
    {
        $this->tableGateway->update(['avatar_url' => null], [self::PK => $id]);
    }

    public function getSystemSetting(string $key): string
    {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1";
        $row = $this->tableGateway->getAdapter()->query($sql)->execute([$key])->current();
        return (string)($row['setting_value'] ?? '');
    }

    public function saveUser(User $user, ?string $passwordHash = null): void
    {
        $data = [
            'username'       => $user->username,
            'email'          => $user->email,
            'google_id'      => $user->googleId !== '' ? $user->googleId : null,
            'full_name'      => $user->fullName,
            'role'           => $user->role,
            'is_approved'    => $user->isApproved ? 1 : 0,
            'nickname'       => $user->nickname,
            'date_of_birth'  => $user->dateOfBirth !== '' ? $user->dateOfBirth : null,
            'avatar_url'     => $user->avatarUrl !== '' ? $user->avatarUrl : null,
            'account_status' => $user->accountStatus,
            'lock_reason'    => $user->lockReason !== '' ? $user->lockReason : null,
            'phone'          => $user->phone !== '' ? $user->phone : null,
            'borrow_limit'   => $user->borrowLimit,
        ];

        if ($passwordHash !== null) {
            $data['password'] = $passwordHash;
        }

        if ($user->id === 0) {
            if ($passwordHash === null) {
                throw new \InvalidArgumentException('Tài khoản mới bắt buộc phải có mật khẩu.');
            }

            $this->tableGateway->insert($data);
            $user->id = (int)$this->tableGateway->getLastInsertValue();
            $user->password = $passwordHash;

            return;
        }

        $this->tableGateway->update($data, [self::PK => $user->id]);

        if ($passwordHash !== null) {
            $user->password = $passwordHash;
        }
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    private function firstUserFromRowset(iterable $rowset): ?User
    {
        foreach ($rowset as $row) {
            return $row instanceof User ? $row : null;
        }

        return null;
    }

    private function extractCount(mixed $current): int
    {
        if (! is_array($current)) {
            return 0;
        }

        return (int) ($current['c'] ?? 0);
    }
}
