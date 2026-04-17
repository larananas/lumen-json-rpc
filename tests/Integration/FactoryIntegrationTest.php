<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class FactoryIntegrationTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testCustomFactoryInjectsDependency(): void
    {
        $sharedService = new \stdClass();
        $sharedService->data = 'injected-value';

        $factory = new class($sharedService) implements HandlerFactoryInterface {
            public function __construct(private object $service) {}
            public function create(string $className, RequestContext $context): object
            {
                if (method_exists($className, 'setService')) {
                    $instance = new $className($context);
                    $instance->setService($this->service);
                    return $instance;
                }
                $ref = new \ReflectionClass($className);
                $constructor = $ref->getConstructor();
                if ($constructor !== null) {
                    $params = $constructor->getParameters();
                    if (!empty($params)) {
                        $firstType = $params[0]->getType();
                        if ($firstType instanceof \ReflectionNamedType && $firstType->getName() === RequestContext::class) {
                            return $ref->newInstance($context);
                        }
                    }
                }
                return new $className();
            }
        };

        $server = $this->createServer();
        $server->setHandlerFactory($factory);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testDefaultFactoryWhenNoneSet(): void
    {
        $server = $this->createServer();

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }
}
