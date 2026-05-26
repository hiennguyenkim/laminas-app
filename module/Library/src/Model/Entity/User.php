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
    public string $googleId      = '';
    public string $password      = '';
    public string $fullName      = '';
    public string $role          = 'student';
    public bool   $isApproved    = false;
    public string $createdAt     = '';
    public string $lastReturnedAt = '';

    // Phase 2.1 — Profile fields
    public string $nickname       = '';
    public string $dateOfBirth    = '';
    public string $avatarUrl      = '';
    public string $accountStatus  = 'active';
    public string $lockReason     = '';
    public string $lockedAt       = '';
    public string $lockedUntil    = '';
    public string $phone          = '';
    public int    $borrowLimit    = 5;

    public int    $borrowCount    = 0;
    public int    $overdueCount   = 0;
    public int    $totalBorrowedCount = 0;

    public function exchangeArray(array $data): void
    {
        $this->id             = (int)    ($data['id'] ?? $data['user_id'] ?? 0);
        $this->username       = (string) ($data['username'] ?? '');
        $this->email          = (string) ($data['email'] ?? '');
        $this->googleId       = (string) ($data['google_id'] ?? '');
        $this->password       = (string) ($data['password'] ?? '');
        $this->fullName       = (string) ($data['full_name'] ?? '');
        $this->role           = (string) ($data['role'] ?? 'student');
        $this->isApproved     = (bool)   ($data['is_approved'] ?? false);
        $this->createdAt      = (string) ($data['created_at'] ?? '');
        $this->lastReturnedAt = (string) ($data['last_returned_at'] ?? '');

        // Phase 2.1
        $this->nickname      = (string) ($data['nickname'] ?? '');
        $this->dateOfBirth   = (string) ($data['date_of_birth'] ?? '');
        $this->avatarUrl     = (string) ($data['avatar_url'] ?? '');
        $this->accountStatus = (string) ($data['account_status'] ?? 'active');
        $this->lockReason    = (string) ($data['lock_reason'] ?? '');
        $this->lockedAt      = (string) ($data['locked_at'] ?? '');
        $this->lockedUntil   = (string) ($data['locked_until'] ?? '');
        $this->phone         = (string) ($data['phone'] ?? '');
        $this->borrowLimit   = (int)    ($data['borrow_limit'] ?? 5);

        $this->borrowCount   = (int)    ($data['borrowCount'] ?? $data['borrow_count'] ?? 0);
        $this->overdueCount  = (int)    ($data['overdueCount'] ?? $data['overdue_count'] ?? 0);
        $this->totalBorrowedCount = (int) ($data['totalBorrowedCount'] ?? $data['total_borrowed'] ?? 0);
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
            'google_id'      => $this->googleId,
            'full_name'      => $this->fullName,
            'role'           => $this->role,
            'is_approved'    => $this->isApproved,
            'nickname'       => $this->nickname,
            'date_of_birth'  => $this->dateOfBirth,
            'avatar_url'     => $this->avatarUrl,
            'account_status' => $this->accountStatus,
            'lock_reason'    => $this->lockReason,
            'locked_until'   => $this->lockedUntil,
            'phone'          => $this->phone,
            'borrow_limit'   => $this->borrowLimit,
        ];
    }
}
