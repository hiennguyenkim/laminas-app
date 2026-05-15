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
                return $this->redirect()->toRoute('library/book');
            }

            return $this->redirect()->toRoute('catalog');
        }

        if ($this->httpRequest()->getUri()->getPath() === '/admin') {
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        return $this->redirect()->toRoute('catalog');
    }
}
