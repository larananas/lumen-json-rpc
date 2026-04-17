<?php

declare(strict_types=1);

namespace App\Handlers\AuthExample;

use Lumen\JsonRpc\Support\RequestContext;

/**
 * User handler with protected methods.
 *
 * Methods in this handler are protected by the library because they match
 * the `user.` prefix in `protected_methods`.
 */
class User
{
    /**
     * Return the authenticated user's profile.
     */
    public function me(RequestContext $context): array
    {
        return [
            'id' => $context->authUserId,
            'email' => $context->getClaim('email'),
            'name' => $context->getClaim('name'),
            'roles' => $context->authRoles,
        ];
    }

    /**
     * Demonstrate application-level authorization.
     *
     * Admins can view any profile. Regular users can only view their own.
     */
    public function get(RequestContext $context, string $id): array
    {
        if (!$context->hasRole('admin') && $context->authUserId !== $id) {
            return [
                'error' => 'You can only view your own profile',
            ];
        }

        $users = [
            '1' => ['id' => '1', 'name' => 'Admin User'],
            '2' => ['id' => '2', 'name' => 'Regular User'],
        ];

        return $users[$id] ?? ['error' => 'User not found'];
    }
}
