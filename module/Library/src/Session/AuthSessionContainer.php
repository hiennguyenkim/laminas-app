<?php

declare(strict_types=1);

namespace Library\Session;

use Laminas\Session\Container;
use Laminas\Session\SessionManager;

/**
 * @psalm-suppress MissingTemplateParam
 * @psalm-suppress PropertyNotSetInConstructor
 * @property int|null $otpUserId
 * @property array|null $user
 * @property string|null $loginCsrfToken
 * @property int|null $resetPasswordUserId
 * @property int|null $otpAttempts
 */
class AuthSessionContainer extends Container
{
    public function __construct(SessionManager $sessionManager)
    {
        parent::__construct('library_auth', $sessionManager);
    }
}
