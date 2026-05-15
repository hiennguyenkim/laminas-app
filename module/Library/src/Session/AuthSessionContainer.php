<?php

declare(strict_types=1);

namespace Library\Session;

use Laminas\Session\Container;
use Laminas\Session\SessionManager;

/**
 * @psalm-suppress MissingTemplateParam
 * @psalm-suppress PropertyNotSetInConstructor
 */
class AuthSessionContainer extends Container
{
    public function __construct(SessionManager $sessionManager)
    {
        parent::__construct('library_auth', $sessionManager);
    }
}
