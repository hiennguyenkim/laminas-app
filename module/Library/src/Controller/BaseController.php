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
     * @return array{id:int, username:string, email:string, full_name:string, role:string, avatar_url:string, nickname:string, account_status:string, locked_until:string}|null
     */
    protected function currentUser(): ?array
    {
        $user = $this->authSession()->user ?? null;

        if (! is_array($user)) {
            return null;
        }

        return [
            'id'             => (int) ($user['id'] ?? 0),
            'username'       => (string) ($user['username'] ?? ''),
            'email'          => (string) ($user['email'] ?? ''),
            'full_name'      => (string) ($user['full_name'] ?? ''),
            'role'           => (string) ($user['role'] ?? ''),
            'avatar_url'     => (string) ($user['avatar_url'] ?? ''),
            'nickname'       => (string) ($user['nickname'] ?? ''),
            'account_status' => (string) ($user['account_status'] ?? 'active'),
            'locked_until'   => (string) ($user['locked_until'] ?? ''),
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

        return $this->redirect()->toRoute('auth', ['action' => 'login']);
    }

    public function onDispatch(\Laminas\Mvc\MvcEvent $e)
    {
        $routeMatch = $e->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $currentUser = $this->currentUser();
        $role = $currentUser['role'] ?? '';

        // If matched route is under admin / library
        if (str_starts_with($routeName, 'library')) {
            if (str_starts_with($routeName, 'auth')) {
                $action = $routeMatch->getParam('action', 'login');
                if ($role === 'student' && $action !== 'logout') {
                    return $this->redirect()->toRoute('student/dashboard');
                }
            } else {
                // Guest (chưa đăng nhập) không được truy cập khu vực admin
                if ($currentUser === null) {
                    $this->flash()->addInfoMessage('Vui lòng đăng nhập để tiếp tục.');
                    return $this->redirect()->toRoute('auth', ['action' => 'login']);
                }
                if ($role === 'student') {
                    $this->flash()->addErrorMessage('Chỉ quản trị viên mới có quyền truy cập.');
                    return $this->redirect()->toRoute('student/dashboard');
                }
            }
        }

        // If matched route is under student
        if (str_starts_with($routeName, 'student')) {
            if ($role === 'admin') {
                $this->flash()->addErrorMessage('Học sinh mới có quyền truy cập trang này.');
                return $this->redirect()->toRoute('library/dashboard');
            }
        }

        return parent::onDispatch($e);
    }

    protected function routeForRole(string $suffix): string
    {
        return $this->isAdmin() ? 'library/' . $suffix : 'student/' . $suffix;
    }

    protected function requireAdmin(): ?Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            $this->flash()->addInfoMessage('Vui lòng đăng nhập để tiếp tục.');

            return $this->redirect()->toRoute('auth', ['action' => 'login']);
        }

        if (($user['role'] ?? '') === 'admin') {
            return null;
        }

        $this->flash()->addErrorMessage('Chỉ quản trị viên mới có quyền truy cập.');

        return $this->redirect()->toRoute('student/dashboard');
    }
}
