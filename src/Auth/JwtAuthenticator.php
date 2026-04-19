<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class JwtAuthenticator implements AuthenticatorInterface
{
    private const SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512'];
    private const ALGO_MAP = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'HS256',
        private readonly string $issuer = '',
        private readonly string $audience = '',
        private readonly int $leeway = 0,
    ) {}

    public function authenticate(string $token): ?UserContext
    {
        $payload = $this->decodeJwt($token);
        if ($payload === null) {
            return null;
        }

        if ($this->issuer !== '' && ($payload['iss'] ?? '') !== $this->issuer) {
            return null;
        }

        if ($this->audience !== '' && !$this->checkAudience($payload['aud'] ?? null, $this->audience)) {
            return null;
        }

        $now = time();
        if (isset($payload['exp']) && $payload['exp'] < ($now - $this->leeway)) {
            return null;
        }

        if (isset($payload['nbf']) && $payload['nbf'] > ($now + $this->leeway)) {
            return null;
        }

        if (isset($payload['iat']) && $payload['iat'] > ($now + $this->leeway)) {
            return null;
        }

        $userId = (string)($payload['sub'] ?? $payload['user_id'] ?? '');
        if ($userId === '') {
            return null;
        }

        return new UserContext(
            userId: $userId,
            claims: $payload,
            roles: (array)($payload['roles'] ?? []),
        );
    }

    private function checkAudience(mixed $aud, string $expected): bool
    {
        if (is_string($aud)) {
            return $aud === $expected;
        }
        if (is_array($aud)) {
            return in_array($expected, $aud, true);
        }
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwt(string $token): ?array
    {
        if (class_exists(\Firebase\JWT\JWT::class)) {
            try {
                \Firebase\JWT\JWT::$leeway = $this->leeway;
                $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($this->secret, $this->algorithm));
                return (array)$decoded;
            } catch (\Exception) {
                return null;
            }
        }

        return $this->decodeJwtManual($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtManual(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = $this->base64UrlDecode($headerB64);
        $payloadJson = $this->base64UrlDecode($payloadB64);

        if ($headerJson === null || $payloadJson === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        if (($header['typ'] ?? '') !== 'JWT') {
            return null;
        }

        $tokenAlg = $header['alg'] ?? '';

        if ($tokenAlg === 'none' || $tokenAlg === '') {
            return null;
        }

        if (!in_array($tokenAlg, self::SUPPORTED_ALGORITHMS, true)) {
            return null;
        }

        if ($tokenAlg !== $this->algorithm) {
            return null;
        }

        $signature = $this->base64UrlDecode($signatureB64);
        if ($signature === null) {
            return null;
        }

        $expectedSignature = $this->computeSignature("$headerB64.$payloadB64", $tokenAlg);
        if ($expectedSignature === null || !hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $payload;
    }

    private function computeSignature(string $input, string $algorithm): ?string
    {
        $hashAlg = self::ALGO_MAP[$algorithm] ?? null;
        if ($hashAlg === null) {
            return null;
        }

        return hash_hmac($hashAlg, $input, $this->secret, true);
    }

    private function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }
}
