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

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->hooks = new HookManager();
        $this->logger = $this->createLogger();
        $this->validateConfig();
        $this->authManager = $this->createAuthManager();
        $this->rateLimitManager = $this->createRateLimitManager();
        $this->fingerprinter = $this->createFingerprinter();
        $this->requestReader = new RequestReader(
            $this->config->get('limits.max_body_size', 1_048_576),
            $this->config->get('compression.request_gzip', true),
        );
        $this->validator = new RequestValidator(
            $this->config->get('validation.strict', true),
        );
        $this->batchProcessor = new BatchProcessor(
            $this->validator,
            $this->config->get('batch.max_items', 100),
        );
        $this->registry = new HandlerRegistry(
            $this->config->get('handlers.paths', []),
            $this->config->get('handlers.namespace', 'Lumen\JsonRpc\\Handlers\\'),
            $this->config->get('handlers.method_separator', '.'),
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
        );

        $this->initAuthDriver();
    }

    public function handle(HttpRequest $httpRequest): HttpResponse
    {
        $correlationId = CorrelationId::generate();

        if ($httpRequest->method !== 'POST') {
            if ($httpRequest->method === 'GET' && $this->config->get('health.enabled', true)) {
                $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId, 'health' => true]);
                $healthJson = json_encode([
                    'status' => 'ok',
                    'server' => $this->config->get('server.name', 'Lumen JSON-RPC'),
                    'version' => $this->config->get('server.version', '1.0.0'),
                ], JSON_THROW_ON_ERROR);
                $response = HttpResponse::json($healthJson);
                $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId, 'health' => true]);
                $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId, 'health' => true]);
                return $response;
            }
            return new HttpResponse('', 405, ['Allow' => 'POST, GET']);
        }

        if ($this->config->get('content_type.strict', false)) {
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

    public function handleJson(string $json, ?RequestContext $context = null): ?string
    {
        $result = $this->engine->handleJson($json, $context);
        return $result->json;
    }

    public function run(): void
    {
        $httpRequest = HttpRequest::fromGlobals();
        $response = $this->handle($httpRequest);
        $response->send();
    }

    public function getEngine(): JsonRpcEngine
    {
        return $this->engine;
    }

    public function setHandlerFactory(HandlerFactoryInterface $factory): self
    {
        $this->dispatcher->setFactory($factory);
        return $this;
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->engine->addMiddleware($middleware);
        return $this;
    }

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
                $fp = $this->fingerprinter->fingerprint($decoded);
                $etag = '"' . $fp . '"';
                $headers = array_merge($result->headers, ['ETag' => $etag]);

                $ifNoneMatch = $httpRequest->getHeaderCaseInsensitive('If-None-Match');
                if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
                    return new HttpResponse('', 304, $headers);
                }

                return $this->buildHttpResponse($json, $statusCode, $httpRequest, $headers);
            }
        }

        return $this->buildHttpResponse($json, $statusCode, $httpRequest, $result->headers);
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

        $responseGzip = $this->config->get('compression.response_gzip', false);
        $acceptEncoding = $httpRequest->getHeaderCaseInsensitive('Accept-Encoding');

        if ($responseGzip && $acceptEncoding !== null && stripos($acceptEncoding, 'gzip') !== false) {
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
                'serverVersion' => $this->config->get('server.version', '1.0.0'),
                'serverName' => $this->config->get('server.name', 'Lumen JSON-RPC'),
            ],
        );
    }

    private function initAuthDriver(): void
    {
        if (!$this->authManager->isEnabled()) {
            return;
        }

        $driver = $this->config->get('auth.driver', 'jwt');

        $requestAuthenticator = match ($driver) {
            'jwt' => new JwtRequestAuthenticator(
                new JwtAuthenticator(
                    secret: $this->config->get('auth.jwt.secret', ''),
                    algorithm: $this->config->get('auth.jwt.algorithm', 'HS256'),
                    issuer: $this->config->get('auth.jwt.issuer', ''),
                    audience: $this->config->get('auth.jwt.audience', ''),
                    leeway: $this->config->get('auth.jwt.leeway', 0),
                ),
                header: $this->config->get('auth.jwt.header', 'Authorization'),
                prefix: $this->config->get('auth.jwt.prefix', 'Bearer '),
            ),
            'api_key' => new ApiKeyAuthenticator(
                header: $this->config->get('auth.api_key.header', 'X-API-Key'),
                keys: $this->config->get('auth.api_key.keys', []),
            ),
            'basic' => new BasicAuthenticator(
                header: $this->config->get('auth.basic.header', 'Authorization'),
                users: $this->config->get('auth.basic.users', []),
            ),
            default => null,
        };

        if ($requestAuthenticator !== null) {
            $this->engine->setRequestAuthenticator($requestAuthenticator);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function fireHook(HookPoint $point, array $context = []): array
    {
        if ($this->config->get('hooks.enabled', true)) {
            return $this->hooks->fire($point, $context);
        }
        return $context;
    }

    private function validateConfig(): void
    {
        if ($this->config->get('auth.enabled', false)) {
            $driver = $this->config->get('auth.driver', 'jwt');
            $allowedDrivers = ['jwt', 'api_key', 'basic'];
            if (!in_array($driver, $allowedDrivers, true)) {
                throw new \RuntimeException(
                    "Invalid auth driver: '{$driver}'. Must be one of: " . implode(', ', $allowedDrivers)
                );
            }
            if ($driver === 'jwt') {
                $secret = $this->config->get('auth.jwt.secret', '');
                if ($secret === '') {
                    throw new \RuntimeException('auth.jwt.secret must be set when auth driver is jwt');
                }
                $algo = $this->config->get('auth.jwt.algorithm', 'HS256');
                if (!in_array($algo, ['HS256', 'HS384', 'HS512'], true)) {
                    throw new \RuntimeException("Unsupported JWT algorithm: $algo. Must be HS256, HS384, or HS512");
                }
                $leeway = $this->config->get('auth.jwt.leeway', 0);
                if (!is_int($leeway) || $leeway < 0) {
                    throw new \RuntimeException('auth.jwt.leeway must be a non-negative integer');
                }
            }
            if ($driver === 'api_key') {
                $keys = $this->config->get('auth.api_key.keys', []);
                if (empty($keys)) {
                    throw new \RuntimeException('auth.api_key.keys must be set when auth driver is api_key');
                }
            }
            if ($driver === 'basic') {
                $users = $this->config->get('auth.basic.users', []);
                if (empty($users)) {
                    throw new \RuntimeException('auth.basic.users must be set when auth driver is basic');
                }
            }
        }

        $algo = $this->config->get('response_fingerprint.algorithm', 'sha256');
        if (!in_array($algo, hash_algos(), true)) {
            throw new \RuntimeException("Invalid fingerprint algorithm: $algo");
        }

        $maxItems = $this->config->get('batch.max_items', 100);
        if (!is_int($maxItems) || $maxItems < 1) {
            throw new \RuntimeException('batch.max_items must be a positive integer');
        }

        $maxBodySize = $this->config->get('limits.max_body_size', 1_048_576);
        if (!is_int($maxBodySize) || $maxBodySize < 1) {
            throw new \RuntimeException('limits.max_body_size must be a positive integer');
        }

        $rateLimitEnabled = $this->config->get('rate_limit.enabled', false);
        if ($rateLimitEnabled) {
            $storagePath = $this->config->get('rate_limit.storage_path', '');
            if ($storagePath === '') {
                throw new \RuntimeException('rate_limit.storage_path must be set when rate limiting is enabled');
            }
            $maxRequests = $this->config->get('rate_limit.max_requests', 100);
            if (!is_int($maxRequests) || $maxRequests < 1) {
                throw new \RuntimeException('rate_limit.max_requests must be a positive integer');
            }
        }
    }

    private function createLogger(): Logger
    {
        if (!$this->config->get('logging.enabled', true)) {
            return new Logger('', 'none');
        }

        $rotator = null;
        if ($this->config->get('log_rotation.enabled', true)) {
            $rotator = new LogRotator(
                $this->config->get('log_rotation.max_size', 10_485_760),
                $this->config->get('log_rotation.max_files', 5),
                $this->config->get('log_rotation.compress', true),
            );
        }

        return new Logger(
            $this->config->get('logging.path', 'logs/app.log'),
            $this->config->get('logging.level', 'info'),
            $this->config->get('logging.sanitize_secrets', true),
            $rotator,
        );
    }

    private function createAuthManager(): AuthManager
    {
        $manager = new AuthManager($this->config->get('auth.enabled', false));
        if ($manager->isEnabled()) {
            $driver = $this->config->get('auth.driver', 'jwt');
            if ($driver === 'jwt') {
                $manager->setAuthenticator(new JwtAuthenticator(
                    secret: $this->config->get('auth.jwt.secret', ''),
                    algorithm: $this->config->get('auth.jwt.algorithm', 'HS256'),
                    issuer: $this->config->get('auth.jwt.issuer', ''),
                    audience: $this->config->get('auth.jwt.audience', ''),
                    leeway: $this->config->get('auth.jwt.leeway', 0),
                ));
            }
        }
        return $manager;
    }

    private function createRateLimitManager(): RateLimitManager
    {
        $manager = new RateLimitManager(
            $this->config->get('rate_limit.enabled', false),
            $this->config->get('rate_limit.strategy', 'ip'),
            $this->config->get('rate_limit.batch_weight', 1),
        );
        if ($manager->isEnabled()) {
            $manager->setLimiter(new FileRateLimiter(
                $this->config->get('rate_limit.max_requests', 100),
                $this->config->get('rate_limit.window_seconds', 60),
                $this->config->get('rate_limit.storage_path', 'storage/rate_limit'),
                $this->config->get('rate_limit.fail_open', true),
            ));
        }
        return $manager;
    }

    private function createFingerprinter(): ResponseFingerprinter
    {
        return new ResponseFingerprinter(
            $this->config->get('response_fingerprint.enabled', false),
            $this->config->get('response_fingerprint.algorithm', 'sha256'),
        );
    }

    private function createDispatcher(): HandlerDispatcher
    {
        $resolver = new MethodResolver(
            $this->config->get('handlers.paths', []),
            $this->config->get('handlers.namespace', 'Lumen\JsonRpc\\Handlers\\'),
            $this->config->get('handlers.method_separator', '.'),
        );

        return new HandlerDispatcher(
            $resolver,
            new ParameterBinder(),
            $this->registry,
        );
    }

    public function getHooks(): HookManager
    {
        return $this->hooks;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getRegistry(): HandlerRegistry
    {
        return $this->registry;
    }
}
