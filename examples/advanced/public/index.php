<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\RequestContext;

// --- Custom Handler Factory (DI injection) ---

class DatabaseService
{
    public array $data = ['items' => ['apple', 'banana', 'cherry']];

    public function findAll(): array
    {
        return $this->data['items'];
    }
}

$db = new DatabaseService();

$factory = new class($db) implements HandlerFactoryInterface {
    public function __construct(private DatabaseService $db) {}

    public function create(string $className, RequestContext $context): object
    {
        $ref = new \ReflectionClass($className);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === DatabaseService::class) {
                return new $className($this->db);
            }
            if ($type instanceof \ReflectionNamedType && $type->getName() === RequestContext::class) {
                return new $className($context);
            }
        }

        return new $className();
    }
};

// --- Logging Middleware ---

$loggingMiddleware = new class implements MiddlewareInterface {
    public function process(Request $request, RequestContext $context, callable $next): ?Response
    {
        error_log("[JSON-RPC] -> {$request->method} (id={$request->id})");
        $response = $next($request, $context);
        error_log("[JSON-RPC] <- " . ($response ? 'response' : 'notification'));
        return $response;
    }
};

// --- Server setup ---

$config = new Config([
    'handlers' => [
        'paths' => [__DIR__ . '/../handlers'],
        'namespace' => 'App\\Handlers\\Advanced\\',
    ],
    'validation' => [
        'strict' => true,
        'schema' => ['enabled' => true],
    ],
    'debug' => true,
    'logging' => ['enabled' => false],
    'auth' => ['enabled' => false],
    'rate_limit' => ['enabled' => false],
    'response_fingerprint' => ['enabled' => false],
]);

$server = new JsonRpcServer($config);
$server->setHandlerFactory($factory);
$server->addMiddleware($loggingMiddleware);
$server->run();
