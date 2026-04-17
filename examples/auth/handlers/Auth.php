<?php

declare(strict_types=1);

namespace App\Handlers\AuthExample;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Authentication handler.
 *
 * This is application code. The library validates JWTs, but it does not
 * implement login or token issuance for you.
 */
class Auth
{
    private const USERS = [
        'admin@example.com' => [
            'id' => '1',
            'password' => 'admin123',
            'name' => 'Admin User',
            'roles' => ['admin', 'user'],
        ],
        'user@example.com' => [
            'id' => '2',
            'password' => 'user123',
            'name' => 'Regular User',
            'roles' => ['user'],
        ],
    ];

    /**
     * Authenticate a user and return a JWT token.
     *
     * @param string $email User email
     * @param string $password User password
     * @return array authentication result with token
     */
    public function login(string $email, string $password): array
    {
        $user = self::USERS[$email] ?? null;

        if ($user === null || $user['password'] !== $password) {
            return [
                'success' => false,
                'error' => 'Invalid credentials',
            ];
        }

        $payload = [
            'sub' => $user['id'],
            'email' => $email,
            'name' => $user['name'],
            'roles' => $user['roles'],
            'iss' => AUTH_EXAMPLE_JWT_ISSUER,
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        return [
            'success' => true,
            'token' => $this->encodeJwt($payload),
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'roles' => $user['roles'],
            ],
        ];
    }

    /**
     * Minimal HMAC JWT encoder for this example only.
     *
     * In a real application, use firebase/php-jwt or another established
     * library for token creation.
     */
    private function encodeJwt(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => AUTH_EXAMPLE_JWT_ALGORITHM];

        $headerEncoded = $this->base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));

        $signature = hash_hmac(
            $this->algorithmToHash(AUTH_EXAMPLE_JWT_ALGORITHM),
            "$headerEncoded.$payloadEncoded",
            AUTH_EXAMPLE_JWT_SECRET,
            true
        );

        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    private function algorithmToHash(string $algorithm): string
    {
        return match ($algorithm) {
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => throw new \InvalidArgumentException('Unsupported algorithm for auth example'),
        };
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
