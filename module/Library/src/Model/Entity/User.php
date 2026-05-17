<?php

declare(strict_types=1);

namespace Library\Model\Entity;

/**
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-suppress PossiblyUnusedMethod
 */
class User
{
    public int    $id            = 0;
    public string $username      = '';
    public string $email         = '';
    public string $password      = '';
    public string $fullName      = '';
    public string $role          = 'student';
    public string $createdAt     = '';
    public string $lastReturnedAt = '';

    // Phase 2.1 — Profile fields
    public string $nickname       = '';
    public string $dateOfBirth    = '';
    public string $avatarUrl      = '';
    public string $accountStatus  = 'active';
    public string $lockReason     = '';
    public string $lockedAt       = '';
    public string $phone          = '';

    public function exchangeArray(array $data): void
    {
        $this->id             = (int)    ($data['id'] ?? $data['user_id'] ?? 0);
        $this->username       = (string) ($data['username'] ?? '');
        $this->email          = (string) ($data['email'] ?? '');
        $this->password       = (string) ($data['password'] ?? '');
        $this->fullName       = (string) ($data['full_name'] ?? '');
        $this->role           = (string) ($data['role'] ?? 'student');
        $this->createdAt      = (string) ($data['created_at'] ?? '');
        $this->lastReturnedAt = (string) ($data['last_returned_at'] ?? '');

        // Phase 2.1
        $this->nickname      = (string) ($data['nickname'] ?? '');
        $this->dateOfBirth   = (string) ($data['date_of_birth'] ?? '');
        $this->avatarUrl     = (string) ($data['avatar_url'] ?? '');
        $this->accountStatus = (string) ($data['account_status'] ?? 'active');
        $this->lockReason    = (string) ($data['lock_reason'] ?? '');
        $this->lockedAt      = (string) ($data['locked_at'] ?? '');
        $this->phone         = (string) ($data['phone'] ?? '');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isLocked(): bool
    {
        return $this->accountStatus === 'locked';
    }

    public function getDisplayName(): string
    {
        if ($this->nickname !== '') {
            return $this->nickname;
        }
        return $this->fullName !== '' ? $this->fullName : $this->username;
    }

    public function getArrayCopy(): array
    {
        return [
            'id'             => $this->id,
            'username'       => $this->username,
            'email'          => $this->email,
            'full_name'      => $this->fullName,
            'role'           => $this->role,
            'nickname'       => $this->nickname,
            'date_of_birth'  => $this->dateOfBirth,
            'avatar_url'     => $this->avatarUrl,
            'account_status' => $this->accountStatus,
            'lock_reason'    => $this->lockReason,
            'phone'          => $this->phone,
        ];
    }
}
