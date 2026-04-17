<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Core;

use Lumen\JsonRpc\Auth\ApiKeyAuthenticator;
use Lumen\JsonRpc\Auth\AuthManager;
use Lumen\JsonRpc\Auth\BasicAuthenticator;
use Lumen\JsonRpc\Auth\JwtAuthenticator;
use Lumen\JsonRpc\Auth\JwtRequestAuthenticator;
use Lumen\JsonRpc\Auth\RequestAuthenticatorInterface;
use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\JsonRpcException;
use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Middleware\MiddlewarePipeline;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\BatchResult;
use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\RateLimit\RateLimitManager;
use Lumen\JsonRpc\Support\HookManager;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Validation\SchemaValidator;

final class JsonRpcEngine
{
    private Config $config;
    private Logger $logger;
    private HookManager $hooks;
    private AuthManager $authManager;
    private RateLimitManager $rateLimitManager;
    private ResponseFingerprinter $fingerprinter;
    private RequestValidator $validator;
    private BatchProcessor $batchProcessor;
    private HandlerDispatcher $dispatcher;
    private HandlerRegistry $registry;
    private MiddlewarePipeline $middlewarePipeline;
    private ?SchemaValidator $schemaValidator = null;
    private ?RequestAuthenticatorInterface $requestAuthenticator = null;

    public function __construct(
        Config $config,
        Logger $logger,
        HookManager $hooks,
        AuthManager $authManager,
        RateLimitManager $rateLimitManager,
        ResponseFingerprinter $fingerprinter,
        RequestValidator $validator,
        BatchProcessor $batchProcessor,
        HandlerDispatcher $dispatcher,
        HandlerRegistry $registry,
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->hooks = $hooks;
        $this->authManager = $authManager;
        $this->rateLimitManager = $rateLimitManager;
        $this->fingerprinter = $fingerprinter;
        $this->validator = $validator;
        $this->batchProcessor = $batchProcessor;
        $this->dispatcher = $dispatcher;
        $this->registry = $registry;
        $this->middlewarePipeline = new MiddlewarePipeline();

        if ($this->config->get('validation.schema.enabled', false)) {
            $this->schemaValidator = new SchemaValidator();
        }
    }

    public function handleJson(string $json, ?RequestContext $context = null): EngineResult
    {
        $correlationId = $context?->correlationId ?? \Lumen\JsonRpc\Support\CorrelationId::generate();

        $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId]);

        $jsonError = null;
        $decoded = $this->decodeJson($json, $jsonError);

        if ($jsonError !== null) {
            $this->logger->error('JSON parse error', [], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'json_parse_error']);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return new EngineResult(
                json: Response::error(null, Error::parseError())->toJson(),
                statusCode: 200,
            );
        }

        if (!is_array($decoded)) {
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'invalid_request_type']);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return new EngineResult(
                json: Response::error(null, Error::invalidRequest('Request must be a JSON object or array'))->toJson(),
                statusCode: 200,
            );
        }

        $rawIsObject = strlen($json) > 0 && $json[0] === '{';

        $batchResult = $this->batchProcessor->process($decoded, $rawIsObject);

        if ($context === null) {
            $context = new RequestContext(
                correlationId: $correlationId,
                headers: [],
                clientIp: '127.0.0.1',
                requestBody: $json,
            );
        }

        if ($this->authManager->isEnabled() && !$context->hasAuth() && !empty($context->headers)) {
            $context = $this->authenticateFromHeaders($context->headers, $context);
        }

        $batchSize = RateLimitManager::computeRawItemCount($decoded);
        $rateResult = $this->rateLimitManager->check($context, $batchSize);
        if (!$rateResult->allowed) {
            $this->logger->warning('Rate limit exceeded', ['ip' => $context->clientIp], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'rate_limit_exceeded']);
            $response = Response::error(null, new Error(-32000, 'Rate limit exceeded', [
                'retryAfter' => $rateResult->resetAt - time(),
            ]));
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 429, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return new EngineResult(
                json: $response->toJson(),
                statusCode: 429,
                headers: [
                    'X-RateLimit-Limit' => (string)$rateResult->limit,
                    'X-RateLimit-Remaining' => '0',
                    'Retry-After' => (string)($rateResult->resetAt - time()),
                ],
            );
        }

        $responses = [];

        if ($batchResult->hasErrors()) {
            $responses = array_merge($responses, $batchResult->errors);
        }

        if ($batchResult->hasRequests()) {
            foreach ($batchResult->requests as $request) {
                $requestResponse = $this->processRequest($request, $context);
                if ($requestResponse !== null) {
                    $responses[] = $requestResponse;
                }
            }
        }

        if (empty($responses)) {
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 204, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return new EngineResult(json: null, statusCode: 204);
        }

        if (!$batchResult->isBatch && count($responses) === 1) {
            $responseJson = $responses[0]->toJson();
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return new EngineResult(json: $responseJson, statusCode: 200);
        }

        $encodedResponses = [];
        foreach ($responses as $r) {
            $encodedResponses[] = $r->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $batchJson = '[' . implode(',', $encodedResponses) . ']';

        $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
        $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
        return new EngineResult(json: $batchJson, statusCode: 200);
    }

    public function processRequest(Request $request, RequestContext $context): ?Response
    {
        if ($request->isNotification && !$this->config->get('notifications.enabled', true)) {
            return null;
        }

        if ($this->authManager->isEnabled() && $this->isMethodProtected($request->method) && !$context->hasAuth()) {
            if (!$request->isNotification) {
                return Response::error($request->id, new Error(-32001, 'Authentication required'));
            }
            return null;
        }

        $hookCtx = $this->fireHook(HookPoint::BEFORE_HANDLER, [
            'method' => $request->method,
            'params' => $request->params,
            'context' => $context,
        ]);

        if ($this->schemaValidator !== null) {
            $schemaError = $this->validateSchema($request, $context);
            if ($schemaError !== null) {
                $this->fireHook(HookPoint::ON_ERROR, [
                    'method' => $request->method,
                    'reason' => 'schema_validation_failed',
                ]);
                if ($request->isNotification) {
                    return null;
                }
                return $schemaError;
            }
        }

        if (!$this->middlewarePipeline->isEmpty()) {
            try {
                $response = $this->middlewarePipeline->process($request, $context, function (Request $req, RequestContext $ctx) use ($hookCtx): ?Response {
                    return $this->executeHandler($req, $ctx, $hookCtx);
                });
                return $response;
            } catch (JsonRpcException $e) {
                $this->fireHook(HookPoint::ON_ERROR, [
                    'method' => $request->method,
                    'reason' => 'middleware_exception',
                ]);
                $this->logger->error("Middleware JSON-RPC error: {$e->getErrorMessage()}", [
                    'method' => $request->method,
                    'code' => $e->getErrorCode(),
                ], $context->correlationId);
                if ($request->isNotification) {
                    return null;
                }
                $errorData = $this->config->get('debug', false) ? [
                    'debug' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ] : $e->getErrorData();
                return Response::error($request->id, new Error(
                    $e->getErrorCode(),
                    $e->getErrorMessage(),
                    $errorData,
                ));
            } catch (\Throwable $e) {
                $this->fireHook(HookPoint::ON_ERROR, [
                    'method' => $request->method,
                    'reason' => 'middleware_exception',
                ]);
                $this->logger->error("Middleware error: {$e->getMessage()}", [
                    'method' => $request->method,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], $context->correlationId);
                if ($request->isNotification) {
                    return null;
                }
                $debugData = $this->config->get('debug', false) ? [
                    'debug' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ] : null;
                return Response::error($request->id, new Error(
                    -32603,
                    'Internal error',
                    $debugData,
                ));
            }
        }

        return $this->executeHandler($request, $context, $hookCtx);
    }

    private function executeHandler(Request $request, RequestContext $context, array $hookCtx = []): ?Response
    {
        try {
            $result = $this->dispatcher->dispatch($request, $context);

            $this->fireHook(HookPoint::AFTER_HANDLER, array_merge($hookCtx, [
                'method' => $request->method,
                'result' => $result,
            ]));

            if ($request->isNotification) {
                if ($this->config->get('notifications.log', true)) {
                    $this->logger->info('Notification processed', ['method' => $request->method], $context->correlationId);
                }
                return null;
            }

            return Response::success($request->id, $result);
        } catch (JsonRpcException $e) {
            $this->fireHook(HookPoint::ON_ERROR, [
                'method' => $request->method,
                'exception' => $e,
            ]);

            $this->logger->error("JSON-RPC error: {$e->getErrorMessage()}", [
                'method' => $request->method,
                'code' => $e->getErrorCode(),
            ], $context->correlationId);

            if ($request->isNotification) {
                return null;
            }

            $errorData = $this->config->get('debug', false) ? [
                'debug' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] : $e->getErrorData();

            return Response::error($request->id, new Error(
                $e->getErrorCode(),
                $e->getErrorMessage(),
                $errorData,
            ));
        } catch (\Throwable $e) {
            $this->fireHook(HookPoint::ON_ERROR, [
                'method' => $request->method,
                'exception' => $e,
            ]);

            $this->logger->error("Unexpected error: {$e->getMessage()}", [
                'method' => $request->method,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], $context->correlationId);

            if ($request->isNotification) {
                return null;
            }

            $debugData = $this->config->get('debug', false) ? [
                'debug' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ] : null;

            return Response::error($request->id, new Error(
                -32603,
                'Internal error',
                $debugData,
            ));
        }
    }

    private function validateSchema(Request $request, RequestContext $context): ?Response
    {
        $resolution = $this->dispatcher->resolveMethod($request->method);
        if ($resolution === null) {
            return null;
        }

        $className = $resolution->className;

        if (!class_exists($className)) {
            if ($resolution->fullPath !== '' && file_exists($resolution->fullPath)) {
                require_once $resolution->fullPath;
            }
        }

        if (!class_exists($className)) {
            return null;
        }

        if (!is_a($className, \Lumen\JsonRpc\Validation\RpcSchemaProviderInterface::class, true)) {
            return null;
        }

        $schemas = $className::rpcValidationSchemas();
        $methodName = $resolution->methodName;

        if (!isset($schemas[$methodName])) {
            return null;
        }

        $schema = $schemas[$methodName];
        $params = $request->params ?? [];

        $errors = $this->schemaValidator->validate($params, $schema);
        if (!empty($errors)) {
            return Response::error($request->id, new Error(
                -32602,
                'Invalid params',
                ['validation' => $errors],
            ));
        }

        return null;
    }

    public function authenticateFromHeaders(array $headers, RequestContext $context): RequestContext
    {
        if (!$this->authManager->isEnabled()) {
            return $context;
        }

        if ($this->requestAuthenticator === null) {
            $this->initializeAuthDriver();
        }

        if ($this->requestAuthenticator !== null) {
            $userContext = $this->requestAuthenticator->authenticateFromHeaders($headers);
            if ($userContext !== null) {
                $this->fireHook(HookPoint::ON_AUTH_SUCCESS, ['userId' => $userContext->userId]);
                $this->logger->info('Auth successful', ['userId' => $userContext->userId], $context->correlationId);
                return $context->withAuth(
                    $userContext->userId,
                    $userContext->claims,
                    $userContext->roles
                );
            }
        }

        $this->fireHook(HookPoint::ON_AUTH_FAILURE, []);
        $this->logger->warning('Auth failed', [], $context->correlationId);
        return $context;
    }

    private function initializeAuthDriver(): void
    {
        $driver = $this->config->get('auth.driver', 'jwt');
        $allowedDrivers = ['jwt', 'api_key', 'basic'];
        if (!in_array($driver, $allowedDrivers, true)) {
            return;
        }

        $this->requestAuthenticator = match ($driver) {
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
        };
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewarePipeline->add($middleware);
        return $this;
    }

    public function setRequestAuthenticator(RequestAuthenticatorInterface $authenticator): self
    {
        $this->requestAuthenticator = $authenticator;
        return $this;
    }

    public function getMiddlewarePipeline(): MiddlewarePipeline
    {
        return $this->middlewarePipeline;
    }

    public function getAuthManager(): AuthManager
    {
        return $this->authManager;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getHooks(): HookManager
    {
        return $this->hooks;
    }

    public function getRegistry(): HandlerRegistry
    {
        return $this->registry;
    }

    public function getFingerprinter(): ResponseFingerprinter
    {
        return $this->fingerprinter;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    private function isMethodProtected(string $method): bool
    {
        $protectedPrefixes = $this->config->get('auth.protected_methods', []);
        if ($protectedPrefixes === []) {
            return false;
        }

        $sep = $this->config->get('handlers.method_separator', '.');

        foreach ($protectedPrefixes as $prefix) {
            $normalizedPrefix = $prefix;
            if (str_ends_with($prefix, '.')) {
                $normalizedPrefix = rtrim($prefix, '.') . $sep;
            }
            if (str_starts_with($method, $normalizedPrefix)) {
                return true;
            }
        }

        return false;
    }

    private function fireHook(HookPoint $point, array $context = []): array
    {
        if ($this->config->get('hooks.enabled', true)) {
            return $this->hooks->fire($point, $context);
        }
        return $context;
    }

    private function decodeJson(string $body, ?string &$error = null): mixed
    {
        if ($body === '') {
            return null;
        }

        $maxDepth = $this->config->get('limits.max_json_depth', 64);
        $decoded = json_decode($body, true, $maxDepth, JSON_BIGINT_AS_STRING);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg();
            return null;
        }

        return $decoded;
    }

    private function getHeaderCaseInsensitive(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === $lower) {
                return $value;
            }
        }
        return null;
    }
}
