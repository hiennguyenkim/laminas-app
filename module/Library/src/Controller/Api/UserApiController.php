<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Library\Model\Entity\User;
use Library\Model\Table\UserTable;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
class UserApiController extends AbstractRestfulController
{
    private UserTable $table;
    public function __construct(UserTable $table)
    {
        $this->table = $table;
    }

    // GET /api/users
    public function getList()
    {
        $users = $this->table->fetchAll();
        $data = [];
        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $data[] = $user->getArrayCopy();
        }
        return $this->jsonResponse($data);
    }

    // GET /api/users/:id
    public function get(mixed $id)
    {

        try {
            $user = $this->table->getUser((int) $id);
            return $this->jsonResponse($user->getArrayCopy());
        } catch (\Exception) {
            return $this->jsonResponse(['error' => 'User not found'], 404);
        }
    }

    // POST /api/users
    public function create(mixed $data)
    {
        $data = $this->resolvePayload($data);
        if (! isset($data['username']) || ! isset($data['password']) || ! isset($data['email'])) {
            return $this->jsonResponse(['error' => 'Missing username, password, or email'], 400);
        }

        $username = trim((string) $data['username']);
        $password = (string) $data['password'];
        $email = trim((string) $data['email']);
        $fullName = isset($data['full_name'])
            ? trim((string) $data['full_name'])
            : $username;
        $role = isset($data['role']) ? (string) $data['role'] : 'student';

        if ($username === '' || $password === '' || $email === '') {
            return $this->jsonResponse(['error' => 'Missing username, password, or email'], 400);
        }

        if ($this->table->usernameExists($username)) {
            return $this->jsonResponse(['error' => 'Username already exists'], 409);
        }

        $user = new User();
        $user->exchangeArray([
            'username'  => $username,
            'email'     => $email,
            'full_name' => $fullName,
            'role'      => $role,
        ]);
        try {
            $this->table->saveUser($user, password_hash($password, PASSWORD_DEFAULT));
            return $this->jsonResponse(['status' => 'success', 'message' => 'User created', 'id' => $user->id], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // PUT /api/users/:id
    public function update(mixed $id, mixed $data)
    {
        $data = $this->resolvePayload($data);
        try {
            $user = $this->table->getUser((int) $id);
            $user->exchangeArray($data);
            $user->id = (int) $id;
            $passwordHash = null;
            if (isset($data['password'])) {
                $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            }

            $this->table->saveUser($user, $passwordHash);
            return $this->jsonResponse(['status' => 'success', 'message' => 'User updated']);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // DELETE /api/users/:id
    public function delete(mixed $id)
    {
        return $this->jsonResponse(['error' => 'Delete operation not implemented for users'], 501);
    }

    /**
     * GET /api/users/search?q=...&role=student&limit=20
     *
     * Endpoint AJAX autocomplete cho thành viên trong form mượn sách.
     */
    public function searchAction(): Response
    {
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();

        // Chỉ cho phép GET
        if (! $request->isGet()) {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Lấy tham số
        $query = trim((string) $request->getQuery('q', ''));
        $role  = trim((string) $request->getQuery('role', ''));
        $limit = max(1, min(50, (int) $request->getQuery('limit', '20')));

        // Query users using fetchAll
        $filters = [];
        if ($query !== '') {
            $filters['search'] = $query;
        }
        if ($role !== '') {
            $filters['role'] = $role;
        }

        $users = $this->table->fetchAll($filters);
        $results = [];
        $count = 0;
        foreach ($users as $user) {
            if ($count >= $limit) {
                break;
            }
            if (! $user instanceof User) {
                continue;
            }

            // Chỉ cho phép tài khoản hoạt động mượn sách
            if ($user->accountStatus === 'locked') {
                continue;
            }

            $results[] = [
                'id'        => $user->id,
                'username'  => $user->username,
                'full_name' => $user->fullName,
                'email'     => $user->email,
                'role'      => $user->role,
                'label'     => $user->fullName . ' (@' . $user->username . ')',
            ];
            $count++;
        }

        return $this->jsonResponse($results);
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment
     */
    private function resolvePayload(mixed $data): array
    {
        $content = $this->getRequest()->getContent();
        $payload = is_string($content) ? json_decode($content, true) : null;
        if (is_array($payload)) {
            return $this->normalizePayload($payload);
        }

        return is_array($data) ? $this->normalizePayload($data) : [];
    }

    /**
     * Helper to return JSON response with correct Vietnamese encoding.
     */
    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->getResponse();
        if (! $response instanceof Response) {
            throw new \RuntimeException('Unexpected response instance.');
        }

        $response->setStatusCode($statusCode);
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        return $response;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
