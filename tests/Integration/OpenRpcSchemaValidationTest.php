<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use JsonSchema\Validator;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class OpenRpcSchemaValidationTest extends TestCase
{
    private JsonRpcServer $server;

    protected function setUp(): void
    {
        $config = new Config([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);

        $this->server = new JsonRpcServer($config);
    }

    public function testGeneratedOpenRpcMatchesBundledSchemaFixture(): void
    {
        $this->server->getRegistry()->registerDescriptor(
            new ProcedureDescriptor(
                method: 'math.add',
                handlerClass: 'App\Handlers\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Add two numbers',
                    'params' => [
                        'a' => ['type' => 'int', 'description' => 'First number', 'required' => true, 'default' => null],
                        'b' => ['type' => 'int', 'description' => 'Second number', 'required' => true, 'default' => null],
                    ],
                    'returnType' => 'int',
                    'returnDescription' => 'Sum of a and b',
                    'errors' => [
                        ['code' => -32602, 'description' => 'Invalid parameters'],
                    ],
                    'exampleRequest' => '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}',
                    'exampleResponse' => '{"jsonrpc":"2.0","result":3,"id":1}',
                ],
            ),
        );

        $docs = (new DocGenerator($this->server->getRegistry()))->generate();
        $spec = json_decode((new OpenRpcGenerator())->generate($docs, 'Test API', '1.0.0'), false);
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/openrpc-schema-1.3.2.json'), false);

        $validator = new Validator();
        $validator->validate($spec, $schema);

        $errors = array_map(
            static fn(array $error): string => sprintf('[%s] %s', $error['property'], $error['message']),
            $validator->getErrors(),
        );

        $this->assertTrue($validator->isValid(), implode("\n", $errors));
    }
}
