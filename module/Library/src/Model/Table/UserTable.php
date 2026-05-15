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

    public function fetchAll(array $filters = []): \Laminas\Db\ResultSet\ResultSetInterface
    {
        return $this->tableGateway->select(function (Select $select) use ($filters): void {
            $select->columns([
                'user_id',
                'username',
                'email',
                'password',
                'full_name',
                'role',
                'created_at',
                'last_returned_at' => new Expression(
                    '(SELECT MAX(br.returned_at) FROM borrow_records br '
                    . 'WHERE br.user_id = users.user_id '
                    . 'AND br.returned_at IS NOT NULL)'
                ),
            ]);

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

            $select->order([
                new Expression("CASE WHEN role = 'admin' THEN 0 ELSE 1 END"),
                'full_name ASC',
                self::PK . ' DESC',
            ]);
        });
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

    public function saveUser(User $user, ?string $passwordHash = null): void
    {
        $data = [
            'username'  => $user->username,
            'email'     => $user->email,
            'full_name' => $user->fullName,
            'role'      => $user->role,
        ];

        if ($passwordHash !== null) {
            $data['password'] = $passwordHash;
        }

        if ($user->id === 0) {
            if ($passwordHash === null) {
                throw new \InvalidArgumentException('Tài khoản mới bắt buộc phải có mật khẩu.');
            }

            $this->tableGateway->insert($data);
            $user->id = $this->tableGateway->getLastInsertValue();
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
