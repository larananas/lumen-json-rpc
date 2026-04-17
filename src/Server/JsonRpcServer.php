<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Server;

use Lumen\JsonRpc\Auth\AuthManager;
use Lumen\JsonRpc\Auth\JwtAuthenticator;
use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\InternalErrorException;
use Lumen\JsonRpc\Exception\InvalidRequestException;
use Lumen\JsonRpc\Exception\JsonRpcException;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Http\HttpResponse;
use Lumen\JsonRpc\Http\RequestReader;
use Lumen\JsonRpc\Log\LogRotator;
use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\BatchResult;
use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\RateLimit\FileRateLimiter;
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
    }

    public function handle(HttpRequest $httpRequest): HttpResponse
    {
        $correlationId = CorrelationId::generate();

        if ($httpRequest->method !== 'POST') {
            if ($httpRequest->method === 'GET' && $this->config->get('health.enabled', true)) {
                $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId, 'health' => true]);
                $response = HttpResponse::json(json_encode([
                    'status' => 'ok',
                    'server' => $this->config->get('server.name', 'Lumen JSON-RPC'),
                    'version' => $this->config->get('server.version', '1.0.0'),
                ]));
                $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId, 'health' => true]);
                $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId, 'health' => true]);
                return $response;
            }
            return new HttpResponse('', 405, ['Allow' => 'POST, GET']);
        }

        $this->fireHook(HookPoint::BEFORE_REQUEST, ['correlationId' => $correlationId]);

        if ($this->config->get('content_type.strict', false)) {
            $contentType = $httpRequest->getHeaderCaseInsensitive('Content-Type');
            if ($contentType === null || stripos($contentType, 'application/json') === false) {
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
            $this->logger->error('Request body too large or decompression failed', [], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'body_too_large_or_decompress_failed']);
            $response = Response::error(null, Error::invalidRequest('Request body too large or decompression failed'));
            $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        if ($body === '') {
            $this->logger->error('Empty request body on POST', [], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'empty_body']);
            $response = Response::error(null, Error::invalidRequest('Empty request body'));
            $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        $jsonError = null;
        $decoded = $this->decodeJson($body, $jsonError);

        if ($jsonError !== null) {
            $this->logger->error('JSON parse error', [], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'json_parse_error']);
            $response = Response::error(null, Error::parseError());
            $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        if (!is_array($decoded)) {
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'invalid_request_type']);
            $response = Response::error(null, Error::invalidRequest('Request must be a JSON object or array'));
            $httpResponse = $this->buildHttpResponse($response->toJson(), 200, $httpRequest);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        $rawIsObject = strlen($body) > 0 && $body[0] === '{';

        $batchResult = $this->batchProcessor->process($decoded, $rawIsObject);

        $requestContext = $this->buildRequestContext($httpRequest, $correlationId, $body);
        $requestContext = $this->authenticate($requestContext, $httpRequest);

        $batchSize = RateLimitManager::computeRawItemCount($decoded);
        $rateResult = $this->rateLimitManager->check($requestContext, $batchSize);
        if (!$rateResult->allowed) {
            $this->logger->warning('Rate limit exceeded', ['ip' => $requestContext->clientIp], $correlationId);
            $this->fireHook(HookPoint::ON_ERROR, ['reason' => 'rate_limit_exceeded']);
            $rateHeaders = [
                'X-RateLimit-Limit' => (string)$rateResult->limit,
                'X-RateLimit-Remaining' => '0',
                'Retry-After' => (string)($rateResult->resetAt - time()),
            ];
            $response = Response::error(null, new Error(-32000, 'Rate limit exceeded', [
                'retryAfter' => $rateResult->resetAt - time(),
            ]));
            $httpResponse = $this->buildHttpResponse($response->toJson(), 429, $httpRequest, $rateHeaders);
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 429, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $httpResponse;
        }

        $responses = [];

        if ($batchResult->hasErrors()) {
            $responses = array_merge($responses, $batchResult->errors);
        }

        if ($batchResult->hasRequests()) {
            foreach ($batchResult->requests as $request) {
                $requestResponse = $this->processRequest($request, $requestContext);
                if ($requestResponse !== null) {
                    $responses[] = $requestResponse;
                }
            }
        }

        if (empty($responses)) {
            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 204, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return HttpResponse::noContent();
        }

        if (!$batchResult->isBatch && count($responses) === 1) {
            $json = $responses[0]->toJson();
            $headers = [];

            if ($this->fingerprinter->isEnabled() && $responses[0]->error === null) {
                $fpData = $responses[0]->toArray();
                $fp = $this->fingerprinter->fingerprint($fpData);
                $etag = '"' . $fp . '"';
                $headers['ETag'] = $etag;

                $ifNoneMatch = $httpRequest->getHeaderCaseInsensitive('If-None-Match');
                if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
                    $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 304, 'correlationId' => $correlationId]);
                    $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
                    return new HttpResponse('', 304, $headers);
                }
            }

            $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
            $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
            return $this->buildHttpResponse($json, 200, $httpRequest, $headers);
        }

        $encodedResponses = [];
        foreach ($responses as $r) {
            $encodedResponses[] = $r->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $json = '[' . implode(',', $encodedResponses) . ']';

        $this->fireHook(HookPoint::ON_RESPONSE, ['status' => 200, 'correlationId' => $correlationId]);
        $this->fireHook(HookPoint::AFTER_REQUEST, ['correlationId' => $correlationId]);
        return $this->buildHttpResponse($json, 200, $httpRequest);
    }

    public function run(): void
    {
        $httpRequest = HttpRequest::fromGlobals();
        $response = $this->handle($httpRequest);
        $response->send();
    }

    private function processRequest(Request $request, RequestContext $context): ?Response
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

    private function authenticate(RequestContext $context, HttpRequest $httpRequest): RequestContext
    {
        if (!$this->authManager->isEnabled()) {
            return $context;
        }

        $authHeaderName = $this->config->get('auth.jwt.header', 'Authorization');
        $authHeader = $httpRequest->getHeaderCaseInsensitive($authHeaderName);
        if ($authHeader === null) {
            return $context;
        }

        $prefix = $this->config->get('auth.jwt.prefix', 'Bearer ');
        if (!str_starts_with($authHeader, $prefix)) {
            return $context;
        }

        $token = substr($authHeader, strlen($prefix));
        if ($token === '') {
            return $context;
        }

        $userContext = $this->authManager->authenticate($token);

        if ($userContext !== null) {
            $this->fireHook(HookPoint::ON_AUTH_SUCCESS, ['userId' => $userContext->userId]);
            $this->logger->info('Auth successful', ['userId' => $userContext->userId], $context->correlationId);
            return $context->withAuth(
                $userContext->userId,
                $userContext->claims,
                $userContext->roles
            );
        }

        $this->fireHook(HookPoint::ON_AUTH_FAILURE, []);
        $this->logger->warning('Auth failed', [], $context->correlationId);
        return $context;
    }

    private function validateConfig(): void
    {
        if ($this->config->get('auth.enabled', false)) {
            $secret = $this->config->get('auth.jwt.secret', '');
            if ($secret === '') {
                throw new \RuntimeException('auth.jwt.secret must be set when auth is enabled');
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
            $manager->setAuthenticator(new JwtAuthenticator(
                secret: $this->config->get('auth.jwt.secret', ''),
                algorithm: $this->config->get('auth.jwt.algorithm', 'HS256'),
                issuer: $this->config->get('auth.jwt.issuer', ''),
                audience: $this->config->get('auth.jwt.audience', ''),
                leeway: $this->config->get('auth.jwt.leeway', 0),
            ));
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
