<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class HomeController extends BaseController
{
    public function __construct(AuthSessionContainer $authSessionContainer)
    {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response
    {
        $currentUser = $this->currentUser();
        if ($currentUser !== null) {
            if (($currentUser['role'] ?? '') === 'admin') {
                return $this->redirect()->toRoute('library/dashboard');
            }
            if (($currentUser['role'] ?? '') === 'student') {
                return $this->redirect()->toRoute('student/dashboard');
            }
        }

        $path = $this->httpRequest()->getUri()->getPath();
        if ($path === '/admin' || $path === '/admin/' || $path === '/student' || $path === '/student/') {
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        return $this->redirect()->toRoute('catalog');
    }
}
