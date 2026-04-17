<?php

declare(strict_types=1);

namespace App\Handlers;

use Lumen\JsonRpc\Support\RequestContext;

/**
 * User management handler.
 * @requiresAuth
 */
class User
{
    public function __construct(
        private readonly RequestContext $context,
    ) {}

    /**
     * Create a new user.
     *
     * @param string $name The user's full name
     * @param string $email The user's email address
     * @return array created user info
     * @throws InvalidParamsException when params are invalid
     * @example-request {"jsonrpc": "2.0", "method": "user.create", "params": {"name": "John", "email": "john@example.com"}, "id": 1}
     * @example-response {"id": 1, "name": "John", "email": "john@example.com"}
     */
    public function create(RequestContext $context, string $name, string $email): array
    {
        return [
            'id' => random_int(1, 999999),
            'name' => $name,
            'email' => $email,
        ];
    }

    /**
     * Get user by ID.
     *
     * @param int $id The user ID
     * @return array user data
     * @throws MethodNotFoundException when user not found
     * @example-request {"jsonrpc": "2.0", "method": "user.get", "params": {"id": 1}, "id": 2}
     * @example-response {"id": 1, "name": "John Doe", "email": "john@example.com"}
     */
    public function get(RequestContext $context, int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Example User',
            'email' => 'user@example.com',
        ];
    }

    /**
     * List users with optional pagination.
     *
     * @param int $limit Maximum number of results (default 10)
     * @param int $offset Number of results to skip (default 0)
     * @return array list of users
     * @example-request {"jsonrpc": "2.0", "method": "user.list", "params": {"limit": 10, "offset": 0}, "id": 3}
     * @example-response {"users": [], "total": 0, "limit": 10, "offset": 0}
     */
    public function list(RequestContext $context, int $limit = 10, int $offset = 0): array
    {
        return [
            'users' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Delete a user by ID.
     *
     * @param int $id The user ID to delete
     * @return array deletion result
     * @example-request {"jsonrpc": "2.0", "method": "user.delete", "params": {"id": 1}, "id": 4}
     * @example-response {"deleted": true}
     */
    public function delete(RequestContext $context, int $id): array
    {
        return ['deleted' => true];
    }
}
