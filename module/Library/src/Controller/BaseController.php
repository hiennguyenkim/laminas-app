<?php

declare(strict_types=1);

namespace Library\Controller;

use Laminas\Http\Response;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\Controller\Plugin\Params;
use Laminas\Mvc\Plugin\FlashMessenger\FlashMessenger;
use Library\Session\AuthSessionContainer;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
abstract class BaseController extends AbstractActionController
{
    public function __construct(private AuthSessionContainer $authSessionContainer)
    {
    }

    protected function authSession(): AuthSessionContainer
    {
        return $this->authSessionContainer;
    }

    protected function httpRequest(): Request
    {
        /** @var Request $request */
        $request = $this->getRequest();

        return $request;
    }

    protected function paramsPlugin(): Params
    {
        /** @var Params $params */
        $params = $this->params();

        return $params;
    }

    protected function flash(): FlashMessenger
    {
        /** @var FlashMessenger $flashMessenger */
        $flashMessenger = $this->plugin('flashMessenger');

        return $flashMessenger;
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment
     */
    protected function postData(): array
    {
        $post = $this->httpRequest()->getPost();
        if (! is_object($post) || ! method_exists($post, 'toArray')) {
            return [];
        }

        $data = $post->toArray();
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    protected function queryString(string $name, string $default = ''): string
    {
        return (string) $this->paramsPlugin()->fromQuery($name, $default);
    }

    protected function routeInt(string $name, int $default = 0): int
    {
        return (int) $this->paramsPlugin()->fromRoute($name, $default);
    }

    /**
     * @return array{id:int, username:string, email:string, full_name:string, role:string}|null
     */
    protected function currentUser(): ?array
    {
        $user = $this->authSession()->user ?? null;

        if (! is_array($user)) {
            return null;
        }

        return [
            'id'        => (int) ($user['id'] ?? 0),
            'username'  => (string) ($user['username'] ?? ''),
            'email'     => (string) ($user['email'] ?? ''),
            'full_name' => (string) ($user['full_name'] ?? ''),
            'role'      => (string) ($user['role'] ?? ''),
        ];
    }

    protected function isAdmin(): bool
    {
        return ($this->currentUser()['role'] ?? '') === 'admin';
    }

    protected function requireLogin(): ?Response
    {
        if ($this->currentUser() !== null) {
            return null;
        }

        $this->flash()->addInfoMessage('Vui lòng đăng nhập để tiếp tục.');

        return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
    }

    protected function requireAdmin(): ?Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            $this->flash()->addInfoMessage('Vui lòng đăng nhập để tiếp tục.');

            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        if (($user['role'] ?? '') === 'admin') {
            return null;
        }

        $this->flash()->addErrorMessage('Chỉ quản trị viên mới có quyền truy cập.');

        return $this->redirect()->toRoute('library/dashboard');
    }
}
