<?php

declare(strict_types=1);

namespace Library\Model\Entity;

/**
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-suppress PossiblyUnusedMethod
 */
class User
{
    public int $id        = 0;
    public string $username  = '';
    public string $email     = '';
    public string $password  = '';
    public string $fullName  = '';
    public string $role      = 'student';
    public string $createdAt = '';
    public string $lastReturnedAt = '';

    public function exchangeArray(array $data): void
    {
        $this->id        = (int)    ($data['id'] ?? $data['user_id'] ?? 0);
        $this->username  = (string) ($data['username'] ?? '');
        $this->email     = (string) ($data['email'] ?? '');
        $this->password  = (string) ($data['password'] ?? '');
        $this->fullName  = (string) ($data['full_name'] ?? '');
        $this->role      = (string) ($data['role'] ?? 'student');
        $this->createdAt = (string) ($data['created_at'] ?? '');
        $this->lastReturnedAt = (string) ($data['last_returned_at'] ?? '');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function getArrayCopy(): array
    {
        return [
            'id'        => $this->id,
            'username'  => $this->username,
            'email'     => $this->email,
            'full_name' => $this->fullName,
            'role'      => $this->role,
        ];
    }
}
