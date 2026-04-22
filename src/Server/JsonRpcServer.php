<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Server;

use Lumen\JsonRpc\Auth\ApiKeyAuthenticator;
use Lumen\JsonRpc\Auth\AuthManager;
use Lumen\JsonRpc\Auth\BasicAuthenticator;
use Lumen\JsonRpc\Auth\JwtAuthenticator;
use Lumen\JsonRpc\Auth\JwtRequestAuthenticator;
use Lumen\JsonRpc\Auth\RequestAuthenticatorInterface;
use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Core\EngineResult;
use Lumen\JsonRpc\Core\JsonRpcEngine;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Http\HttpResponse;
use Lumen\JsonRpc\Http\RequestReader;
use Lumen\JsonRpc\Log\LogRotator;
use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\RateLimit\FileRateLimiter;
use Lumen\JsonRpc\RateLimit\RateLimiterInterface;
use Lumen\JsonRpc\RateLimit\RateLimitManager;
use Lumen\JsonRpc\Support\Compressor;
use Lumen\JsonRpc\Support\CorrelationId;
use Lumen\JsonRpc\Support\HookManager;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use Throwable;

final class JsonRpcServer
{
    private Config $config;
    private Logger $logger;
    private HookManager $hooks;
    private AuthManager $authManager;
    private RateLimitManager $rateLimitManager;
    private ResponseFingerprinter $fingerprinter;
    private RequestReader $requestReader;
    private RequestValidator $validator;
    private BatchProcessor $batchProcessor;
    private HandlerDispatcher $dispatcher;
    private HandlerRegistry $registry;
    private JsonRpcEngine $engine;
    private ?RequestAuthenticatorInterface $requestAuthenticator = null;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->hooks = new HookManager();
        $this->logger = $this->createLogger();
        $this->validateConfig();
        $this->authManager = $this->createAuthManager();
        $this->requestAuthenticator = $this->createRequestAuthenticator();
        $this->rateLimitManager = $this->createRateLimitManager();
        $this->fingerprinter = $this->createFingerprinter();
        $this->requestReader = new RequestReader(
            $this->configInt('limits.max_body_size', 1_048_576),
            $this->configBool('compression.request_gzip', true),
        );
        $this->validator = new RequestValidator(
            $this->configBool('validation.strict', false),
        );
        $this->batchProcessor = new BatchProcessor(
            $this->validator,
            $this->configInt('batch.max_items', 100),
        );
        $this->registry = new HandlerRegistry(
            $this->configStringList('handlers.paths', []),
            $this->configString('handlers.namespace', 'Lumen\JsonRpc\\Handlers\\'),
            $this->configString('handlers.method_separator', '.'),
        );
        $this->dispatcher = $this->createDispatcher();

        $this->registry->discover();

        $this->engine = new JsonRpcEngine(
            $this->config,
            $this->logger,
            $this->hooks,
            $this->authManager,
            $this->rateLimitManager,
            $this->fingerprinter,
            $this->batchProcessor,
            $this->dispatcher,
            $this->registry,
            $this->requestAuthenticator,
        );
    }

    public function handle(HttpRequest $httpRequest): HttpResponse
    {
        $correlationId = CorrelationId::generate();

        if ($httpRequest->method !== 'POST') {
            if ($httpRequest->method === 'GET' && $this->configBool('health.enabled', true)) {
                $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId, 'health' => true]);
                $healthJson = json_encode([
                    'status' => 'ok',
                    'server' => $this->configString('server.name', 'Lumen JSON-RPC'),
                    'version' => $this->configString('server.version', '1.0.0'),
                ], JSON_THROW_ON_ERROR);
                $response = HttpResponse::json($healthJson);
                $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId, 'health' => true]);
                $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId, 'health' => true]);
                return $response;
            }
            return new HttpResponse('', 405, ['Allow' => $this->getAllowedMethodsHeader()]);
        }

        if ($this->configBool('content_type.strict', false)) {
            $contentType = $httpRequest->getHeaderCaseInsensitive('Content-Type');
            if ($contentType === null || stripos($contentType, 'application/json') === false) {
                $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId]);
                $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'invalid_content_type']);
                $response = Response::error(null, Error::invalidRequest('Content-Type must be application/json'));
                $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
                $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
                $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
                return $httpResponse;
            }
        }

        $body = $this->requestReader->read($httpRequest);
        if ($body === '' && $httpRequest->body !== '') {
            $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId]);
            $this->logger->error('Request body too large or decompression failed', [], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'body_too_large_or_decompress_failed']);
            $response = Response::error(null, Error::invalidRequest('Request body too large or decompression failed'));
            $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        if ($body === '') {
            $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId]);
            $this->logger->error('Empty request body on POST', [], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'empty_body']);
            $response = Response::error(null, Error::invalidRequest('Empty request body'));
            $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        $requestContext = $this->buildRequestContext($httpRequest, $correlationId, $body);

        $result = $this->engine->handleJson($body, $requestContext);

        return $this->engineResultToHttpResponse($result, $httpRequest);
    }

    /**
     * Stable transport-agnostic entry point for direct JSON usage.
     */
    public function handleJson(string $json, ?RequestContext $context = null): ?string
    {
        $result = $this->engine->handleJson($json, $context);
        return $result->json;
    }

    /**
     * Stable helper for resolving configured auth from request headers.
     */
    public function authenticateContext(RequestContext $context): RequestContext
    {
        if ($context->hasAuth() || empty($context->headers)) {
            return $context;
        }

        return $this->engine->authenticateFromHeaders($context->headers, $context);
    }

    public function run(): void
    {
        $httpRequest = HttpRequest::fromGlobals();
        $response = $this->handle($httpRequest);
        $response->send();
    }

    /**
     * Internal escape hatch for advanced integrations.
     *
     * JsonRpcServer remains the stable public entry point. The engine API is
     * not covered by backward-compatibility guarantees between minor versions.
     */
    public function getEngine(): JsonRpcEngine
    {
        return $this->engine;
    }

    /**
     * Stable extension point for custom handler construction.
     */
    public function setHandlerFactory(HandlerFactoryInterface $factory): self
    {
        $this->dispatcher->setFactory($factory);
        return $this;
    }

    /**
     * Stable extension point for request middleware.
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->engine->addMiddleware($middleware);
        return $this;
    }

    /**
     * Stable extension point for custom header-based request authentication.
     */
    public function setRequestAuthenticator(RequestAuthenticatorInterface $authenticator): self
    {
        $this->requestAuthenticator = $authenticator;
        $this->engine->setRequestAuthenticator($authenticator);
        return $this;
    }

    /**
     * Stable extension point for custom rate limit backends.
     */
    public function setRateLimiter(RateLimiterInterface $limiter): self
    {
        $this->rateLimitManager->setLimiter($limiter);
        return $this;
    }

    private function engineResultToHttpResponse(EngineResult $result, HttpRequest $httpRequest): HttpResponse
    {
        if ($result->isNoContent()) {
            return HttpResponse::noContent();
        }

        $statusCode = $result->statusCode;
        $json = $result->json ?? '';

        if ($statusCode === 200 && $this->fingerprinter->isEnabled()) {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && !isset($decoded[0]) && !isset($decoded['error'])) {
                $fp = $this->fingerprinter->fingerprint([
                    'encoding' => $this->getResponseEncodingVariant($httpRequest),
                    'payload' => $decoded,
                ]);
                $etag = '"' . $fp . '"';
                $headers = array_merge($result->headers, ['ETag' => $etag]);

                $ifNoneMatch = $httpRequest->getHeaderCaseInsensitive('If-None-Match');
                if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
                    if ($this->shouldAdvertiseEncodingVary($httpRequest)) {
                        $headers['Vary'] = 'Accept-Encoding';
                    }

                    return new HttpResponse('', 304, $headers);
                }

                return $this->buildHttpResponse($json, $statusCode, $httpRequest, $headers);
            }
        }

        return $this->buildHttpResponse($json, $statusCode, $httpRequest, $result->headers);
    }

    private function getAllowedMethodsHeader(): string
    {
        if ($this->configBool('health.enabled', true)) {
            return 'POST, GET';
        }

        return 'POST';
    }

    private function shouldAdvertiseEncodingVary(HttpRequest $httpRequest): bool
    {
        return $this->getResponseEncodingVariant($httpRequest) === 'gzip';
    }

    private function getResponseEncodingVariant(HttpRequest $httpRequest): string
    {
        if (!$this->configBool('compression.response_gzip', false)) {
            return 'identity';
        }

        $acceptEncoding = $httpRequest->getHeaderCaseInsensitive('Accept-Encoding');

        if ($acceptEncoding === null || stripos($acceptEncoding, 'gzip') === false || !Compressor::isZlibAvailable()) {
            return 'identity';
        }

        return 'gzip';
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function buildHttpResponse(
        string $json,
        int $statusCode,
        HttpRequest $httpRequest,
        array $extraHeaders = [],
    ): HttpResponse {
        $headers = array_merge(['Content-Type' => 'application/json'], $extraHeaders);

        if ($this->getResponseEncodingVariant($httpRequest) === 'gzip') {
            $compressed = Compressor::encodeGzip($json);
            if ($compressed !== null) {
                $headers['Content-Encoding'] = 'gzip';
                $headers['Vary'] = 'Accept-Encoding';
                return new HttpResponse($compressed, $statusCode, $headers);
            }
        }

        return new HttpResponse($json, $statusCode, $headers);
    }

    private function buildRequestContext(HttpRequest $httpRequest, string $correlationId, string $requestBody = ''): RequestContext
    {
        return new RequestContext(
            correlationId: $correlationId,
            headers: $httpRequest->headers,
            clientIp: $httpRequest->clientIp,
            rawBody: $httpRequest->body,
            requestBody: $requestBody !== '' ? $requestBody : null,
            attributes: [
                'serverVersion' => $this->configString('server.version', '1.0.0'),
                'serverName' => $this->configString('server.name', 'Lumen JSON-RPC'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function fireHook(HookPoint $point, array $context = []): array
    {
        if ($this->configBool('hooks.enabled', true)) {
            if (!$this->configBool('hooks.isolate_exceptions', true)) {
                return $this->hooks->fire($point, $context);
            }

            $correlationId = is_string($context['correlationId'] ?? null) ? $context['correlationId'] : null;

            return $this->hooks->fire(
                $point,
                $context,
                function (Throwable $exception, HookPoint $failedPoint) use ($correlationId): void {
                    $this->logger->warning('Hook callback failed; continuing request', [
                        'hook' => $failedPoint->value,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ], $correlationId);
                }
            );
        }
        return $context;
    }

    private function validateConfig(): void
    {
        if ($this->configBool('auth.enabled', false)) {
            $driver = $this->configString('auth.driver', 'jwt');
            $allowedDrivers = ['jwt', 'api_key', 'basic'];
            if (!in_array($driver, $allowedDrivers, true)) {
                throw new \RuntimeException(
                    "Invalid auth driver: '{$driver}'. Must be one of: " . implode(', ', $allowedDrivers)
                );
            }
            if ($driver === 'jwt') {
                $secret = $this->configString('auth.jwt.secret', '');
                if ($secret === '') {
                    throw new \RuntimeException('auth.jwt.secret must be set when auth driver is jwt');
                }
                $algo = $this->configString('auth.jwt.algorithm', 'HS256');
                if (!in_array($algo, ['HS256', 'HS384', 'HS512'], true)) {
                    throw new \RuntimeException("Unsupported JWT algorithm: $algo. Must be HS256, HS384, or HS512");
                }
                $leeway = $this->configInt('auth.jwt.leeway', 0);
                if ($leeway < 0) {
                    throw new \RuntimeException('auth.jwt.leeway must be a non-negative integer');
                }
            }
            if ($driver === 'api_key') {
                $keys = $this->configArray('auth.api_key.keys', []);
                if (empty($keys)) {
                    throw new \RuntimeException('auth.api_key.keys must be set when auth driver is api_key');
                }

                foreach ($keys as $token => $tokenConfig) {
                    if (!is_string($token) || $token === '') {
                        throw new \RuntimeException('auth.api_key.keys must use non-empty string tokens');
                    }
                    if (!is_array($tokenConfig)) {
                        throw new \RuntimeException("auth.api_key.keys.{$token} must be an array");
                    }

                    /** @var array<int|string, mixed> $tokenConfig */
                    $this->assertOptionalIdentityFields("auth.api_key.keys.{$token}", $tokenConfig);
                }
            }
            if ($driver === 'basic') {
                $users = $this->configArray('auth.basic.users', []);
                if (empty($users)) {
                    throw new \RuntimeException('auth.basic.users must be set when auth driver is basic');
                }

                foreach ($users as $username => $userConfig) {
                    if (!is_string($username) || $username === '') {
                        throw new \RuntimeException('auth.basic.users must use non-empty string usernames');
                    }
                    if (!is_array($userConfig)) {
                        throw new \RuntimeException("auth.basic.users.{$username} must be an array");
                    }

                    $password = $userConfig['password'] ?? null;
                    $passwordHash = $userConfig['password_hash'] ?? null;

                    if (!is_string($password) && !is_string($passwordHash)) {
                        throw new \RuntimeException(
                            "auth.basic.users.{$username} must define either password or password_hash"
                        );
                    }

                    if ((is_string($password) && $password === '') || (is_string($passwordHash) && $passwordHash === '')) {
                        throw new \RuntimeException(
                            "auth.basic.users.{$username} password values must not be empty"
                        );
                    }

                    /** @var array<int|string, mixed> $userConfig */
                    $this->assertOptionalIdentityFields("auth.basic.users.{$username}", $userConfig);
                }
            }
        }

        $algo = $this->configString('response_fingerprint.algorithm', 'sha256');
        if (!in_array($algo, hash_algos(), true)) {
            throw new \RuntimeException("Invalid fingerprint algorithm: $algo");
        }

        $maxItems = $this->configInt('batch.max_items', 100);
        if ($maxItems < 1) {
            throw new \RuntimeException('batch.max_items must be a positive integer');
        }

        $maxBodySize = $this->configInt('limits.max_body_size', 1_048_576);
        if ($maxBodySize < 1) {
            throw new \RuntimeException('limits.max_body_size must be a positive integer');
        }

        $rateLimitEnabled = $this->configBool('rate_limit.enabled', false);
        if ($rateLimitEnabled) {
            $storagePath = $this->configString('rate_limit.storage_path', '');
            if ($storagePath === '') {
                throw new \RuntimeException('rate_limit.storage_path must be set when rate limiting is enabled');
            }
            $maxRequests = $this->configInt('rate_limit.max_requests', 100);
            if ($maxRequests < 1) {
                throw new \RuntimeException('rate_limit.max_requests must be a positive integer');
            }
        }
    }

    private function createLogger(): Logger
    {
        if (!$this->configBool('logging.enabled', true)) {
            return new Logger('', 'none');
        }

        $rotator = null;
        if ($this->configBool('log_rotation.enabled', true)) {
            $rotator = new LogRotator(
                $this->configInt('log_rotation.max_size', 10_485_760),
                $this->configInt('log_rotation.max_files', 5),
                $this->configBool('log_rotation.compress', true),
            );
        }

        return new Logger(
            $this->configString('logging.path', 'logs/app.log'),
            $this->configString('logging.level', 'info'),
            $this->configBool('logging.sanitize_secrets', true),
            $rotator,
        );
    }

    private function createAuthManager(): AuthManager
    {
        $manager = new AuthManager($this->configBool('auth.enabled', false));
        if ($manager->isEnabled() && $this->configString('auth.driver', 'jwt') === 'jwt') {
            $manager->setAuthenticator($this->createJwtAuthenticator());
        }

        return $manager;
    }

    private function createJwtAuthenticator(): JwtAuthenticator
    {
        return new JwtAuthenticator(
            secret: $this->configString('auth.jwt.secret', ''),
            algorithm: $this->configString('auth.jwt.algorithm', 'HS256'),
            issuer: $this->configString('auth.jwt.issuer', ''),
            audience: $this->configString('auth.jwt.audience', ''),
            leeway: $this->configInt('auth.jwt.leeway', 0),
        );
    }

    private function createRequestAuthenticator(): ?RequestAuthenticatorInterface
    {
        if (!$this->authManager->isEnabled()) {
            return null;
        }

        return match ($this->configString('auth.driver', 'jwt')) {
            'jwt' => new JwtRequestAuthenticator(
                $this->createJwtAuthenticator(),
                header: $this->configString('auth.jwt.header', 'Authorization'),
                prefix: $this->configString('auth.jwt.prefix', 'Bearer '),
            ),
            'api_key' => $this->createApiKeyAuthenticator(),
            'basic' => $this->createBasicAuthenticator(),
            default => null,
        };
    }

    private function createApiKeyAuthenticator(): ApiKeyAuthenticator
    {
        /** @var array<string, array{user_id?: string, claims?: array<string, mixed>, roles?: array<int, string>}> $keys */
        $keys = $this->configArray('auth.api_key.keys', []);

        return new ApiKeyAuthenticator(
            header: $this->configString('auth.api_key.header', 'X-API-Key'),
            keys: $keys,
        );
    }

    private function createBasicAuthenticator(): BasicAuthenticator
    {
        /** @var array<string, array{password?: string, password_hash?: string, user_id?: string, claims?: array<string, mixed>, roles?: array<int, string>}> $users */
        $users = $this->configArray('auth.basic.users', []);

        return new BasicAuthenticator(
            header: $this->configString('auth.basic.header', 'Authorization'),
            users: $users,
        );
    }

    private function createRateLimitManager(): RateLimitManager
    {
        $manager = new RateLimitManager(
            $this->configBool('rate_limit.enabled', false),
            $this->configString('rate_limit.strategy', 'ip'),
            $this->configInt('rate_limit.batch_weight', 1),
        );
        if ($manager->isEnabled()) {
            $manager->setLimiter(new FileRateLimiter(
                $this->configInt('rate_limit.max_requests', 100),
                $this->configInt('rate_limit.window_seconds', 60),
                $this->configString('rate_limit.storage_path', 'storage/rate_limit'),
                $this->configBool('rate_limit.fail_open', false),
            ));
        }
        return $manager;
    }

    private function createFingerprinter(): ResponseFingerprinter
    {
        return new ResponseFingerprinter(
            $this->configBool('response_fingerprint.enabled', false),
            $this->configString('response_fingerprint.algorithm', 'sha256'),
        );
    }

    private function createDispatcher(): HandlerDispatcher
    {
        $resolver = new MethodResolver(
            $this->configStringList('handlers.paths', []),
            $this->configString('handlers.namespace', 'Lumen\JsonRpc\\Handlers\\'),
            $this->configString('handlers.method_separator', '.'),
        );

        return new HandlerDispatcher(
            $resolver,
            new ParameterBinder(),
            $this->registry,
        );
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);
        if (!is_bool($value)) {
            throw new \RuntimeException("{$key} must be a boolean");
        }

        return $value;
    }

    private function configInt(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);
        if (!is_int($value)) {
            throw new \RuntimeException("{$key} must be an integer");
        }

        return $value;
    }

    private function configString(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);
        if (!is_string($value)) {
            throw new \RuntimeException("{$key} must be a string");
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $default
     * @return array<int|string, mixed>
     */
    private function configArray(string $key, array $default): array
    {
        $value = $this->config->get($key, $default);
        if (!is_array($value)) {
            throw new \RuntimeException("{$key} must be an array");
        }

        /** @var array<int|string, mixed> $value */

        return $value;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function configStringList(string $key, array $default): array
    {
        $values = $this->configArray($key, $default);
        $strings = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException("{$key} must be an array of non-empty strings");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<int|string, mixed> $identityConfig
     */
    private function assertOptionalIdentityFields(string $prefix, array $identityConfig): void
    {
        $userId = $identityConfig['user_id'] ?? null;
        if ($userId !== null && !is_string($userId)) {
            throw new \RuntimeException("{$prefix}.user_id must be a string when set");
        }

        $claims = $identityConfig['claims'] ?? [];
        if (!is_array($claims)) {
            throw new \RuntimeException("{$prefix}.claims must be an array when set");
        }

        $roles = $identityConfig['roles'] ?? [];
        if (!is_array($roles)) {
            throw new \RuntimeException("{$prefix}.roles must be an array when set");
        }

        $this->assertStringList("{$prefix}.roles", array_values($roles));
    }

    /**
     * @param array<int|string, mixed> $values
     */
    private function assertStringList(string $key, array $values): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException("{$key} must contain only non-empty strings");
            }
        }
    }

    public function getHooks(): HookManager
    {
        return $this->hooks;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * Stable registry access for explicit procedure descriptors.
     */
    public function getRegistry(): HandlerRegistry
    {
        return $this->registry;
    }
}
