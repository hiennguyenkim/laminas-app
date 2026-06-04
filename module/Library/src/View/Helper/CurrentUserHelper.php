<?php

declare(strict_types=1);

namespace Library\View\Helper;

use Library\Session\AuthSessionContainer;
use Laminas\View\Helper\AbstractHelper;

/**
 * @psalm-suppress DeprecatedClass
 */
class CurrentUserHelper extends AbstractHelper
{
    public function __construct(
        private AuthSessionContainer $authSession
    ) {
    }

    /**
     * @return array{id:int, username:string, full_name:string, role:string, email:string, avatar_url:string, nickname:string, account_status:string, locked_until:string}|null
     */
    public function __invoke(): ?array
    {
        $user = $this->authSession->user ?? null;

        if (! is_array($user)) {
            return null;
        }

        return [
            'id'             => (int) ($user['id'] ?? 0),
            'username'       => (string) ($user['username'] ?? ''),
            'full_name'      => (string) ($user['full_name'] ?? ''),
            'role'           => (string) ($user['role'] ?? ''),
            'email'          => (string) ($user['email'] ?? ''),
            'avatar_url'     => (string) ($user['avatar_url'] ?? ''),
            'nickname'       => (string) ($user['nickname'] ?? ''),
            'account_status' => (string) ($user['account_status'] ?? 'active'),
            'locked_until'   => (string) ($user['locked_until'] ?? ''),
        ];
    }
}
