<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcGeneratorTest extends TestCase
{
    private OpenRpcGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new OpenRpcGenerator();
    }

    public function testGeneratesValidOpenRpcStructure(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.get',
                description: 'Get a user by ID',
                params: [
                    'id' => ['type' => 'int', 'description' => 'User ID', 'required' => true, 'default' => null],
                ],
                returnType: 'array',
                returnDescription: 'User object',
            ),
        ];

        $output = $this->generator->generate($docs, 'Test API', '1.2.0', 'Test description');
        $data = json_decode($output, true);

        $this->assertSame('1.3.2', $data['openrpc']);
        $this->assertSame('Test API', $data['info']['title']);
        $this->assertSame('1.2.0', $data['info']['version']);
        $this->assertSame('Test description', $data['info']['description']);
        $this->assertCount(1, $data['methods']);
        $this->assertSame('user.get', $data['methods'][0]['name']);
        $this->assertSame('Get a user by ID', $data['methods'][0]['description']);
    }

    public function testMethodHasParams(): void
    {
        $docs = [
            new MethodDoc(
                name: 'math.add',
                params: [
                    'a' => ['type' => 'int', 'description' => 'First number', 'required' => true, 'default' => null],
                    'b' => ['type' => 'int', 'description' => 'Second number', 'required' => true, 'default' => null],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertCount(2, $data['methods'][0]['params']);
        $this->assertSame('a', $data['methods'][0]['params'][0]['name']);
        $this->assertSame('b', $data['methods'][0]['params'][1]['name']);
    }

    public function testRequiredParamHasRequiredTrue(): void
    {
        $docs = [
            new MethodDoc(
                name: 'search',
                params: [
                    'query' => ['type' => 'string', 'description' => 'Search query', 'required' => true],
                    'limit' => ['type' => 'int', 'description' => 'Max results', 'required' => false, 'default' => 10],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $queryParam = $data['methods'][0]['params'][0];
        $this->assertTrue($queryParam['required']);

        $limitParam = $data['methods'][0]['params'][1];
        $this->assertFalse($limitParam['required']);
        $this->assertSame(10, $limitParam['schema']['default']);
    }

    public function testRequiresAuthUsesVendorExtension(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.delete',
                requiresAuth: true,
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertTrue($data['methods'][0]['x-requiresAuth']);
        $this->assertArrayNotHasKey('annotations', $data['methods'][0]);
    }

    public function testResultIncludesDescription(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test',
                returnType: 'array',
                returnDescription: 'A list of items',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $result = $data['methods'][0]['result'];
        $this->assertSame('result', $result['name']);
        $this->assertSame('A list of items', $result['description']);
        $this->assertSame('array', $result['schema']['type']);
    }

    public function testResultWithEmptyDescription(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test',
                returnType: 'string',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertSame('', $data['methods'][0]['result']['description']);
    }

    public function testEmptyMethodsList(): void
    {
        $output = $this->generator->generate([], 'Empty API');
        $data = json_decode($output, true);

        $this->assertCount(0, $data['methods']);
        $this->assertSame('Empty API', $data['info']['title']);
    }

    public function testPhpTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'types',
                params: [
                    's' => ['type' => 'string', 'description' => '', 'required' => true],
                    'i' => ['type' => 'int', 'description' => '', 'required' => true],
                    'f' => ['type' => 'float', 'description' => '', 'required' => true],
                    'b' => ['type' => 'bool', 'description' => '', 'required' => true],
                    'a' => ['type' => 'array', 'description' => '', 'required' => true],
                ],
                returnType: 'mixed',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);
        $params = $data['methods'][0]['params'];

        $this->assertSame('string', $params[0]['schema']['type']);
        $this->assertSame('integer', $params[1]['schema']['type']);
        $this->assertSame('number', $params[2]['schema']['type']);
        $this->assertSame('boolean', $params[3]['schema']['type']);
        $this->assertSame('array', $params[4]['schema']['type']);
        $this->assertArrayHasKey('items', $params[4]['schema']);

        $resultSchema = $data['methods'][0]['result']['schema'];
        $this->assertArrayHasKey('description', $resultSchema);
        $this->assertSame('Any value', $resultSchema['description']);
    }

    public function testNullableTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'nullable',
                params: [
                    'val' => ['type' => '?string', 'description' => '', 'required' => false],
                ],
                returnType: '?int',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $schema = $data['methods'][0]['params'][0]['schema'];
        $this->assertSame('string', $schema['oneOf'][0]['type']);
        $this->assertSame('null', $schema['oneOf'][1]['type']);
    }

    public function testErrorsStructureHasCodeMessageDescription(): void
    {
        $docs = [
            new MethodDoc(
                name: 'risky',
                errors: [
                    ['code' => '-32001', 'description' => 'Not allowed'],
                    ['type' => 'Exception', 'description' => 'Something broke'],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertCount(1, $data['methods'][0]['errors']);
        $error = $data['methods'][0]['errors'][0];
        $this->assertSame(-32001, $error['code']);
        $this->assertSame('Not allowed', $error['message']);
    }

    public function testErrorWithExplicitMessageUsesMessageOverDescription(): void
    {
        $docs = [
            new MethodDoc(
                name: 'explicit',
                errors: [
                    ['code' => -32602, 'message' => 'Custom error message', 'description' => 'Detailed description'],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $error = $data['methods'][0]['errors'][0];
        $this->assertSame(-32602, $error['code']);
        $this->assertSame('Custom error message', $error['message']);
        $this->assertSame('Detailed description', $error['data']);
    }

    public function testErrorWithCodeOnlyFallsBackToDescription(): void
    {
        $docs = [
            new MethodDoc(
                name: 'fallback',
                errors: [
                    ['code' => -32603, 'description' => 'Internal server error detail'],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $error = $data['methods'][0]['errors'][0];
        $this->assertSame(-32603, $error['code']);
        $this->assertSame('Internal server error detail', $error['message']);
        $this->assertArrayNotHasKey('data', $error);
    }

    public function testErrorCodeIsIntNotString(): void
    {
        $docs = [
            new MethodDoc(
                name: 'typed',
                errors: [
                    ['code' => '32001', 'description' => 'Custom error'],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $error = $data['methods'][0]['errors'][0];
        $this->assertIsInt($error['code']);
        $this->assertSame(32001, $error['code']);
    }

    public function testNoErrorsArrayWhenEmpty(): void
    {
        $docs = [
            new MethodDoc(name: 'safe'),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertArrayNotHasKey('errors', $data['methods'][0]);
    }

    public function testOutputIsDeterministic(): void
    {
        $docs = [
            new MethodDoc(name: 'a.method', description: 'First'),
            new MethodDoc(name: 'b.method', description: 'Second'),
        ];

        $output1 = $this->generator->generate($docs, 'Test', '1.0.0');
        $output2 = $this->generator->generate($docs, 'Test', '1.0.0');

        $this->assertSame($output1, $output2);
    }

    public function testServersField(): void
    {
        $output = $this->generator->generate([]);
        $data = json_decode($output, true);

        $this->assertCount(1, $data['servers']);
        $this->assertSame('default', $data['servers'][0]['name']);
        $this->assertSame('http://localhost/', $data['servers'][0]['url']);
    }

    public function testUnionTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'mixed_type',
                params: [
                    'val' => ['type' => 'int|string', 'description' => '', 'required' => true],
                ],
                returnType: 'int|string',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $schema = $data['methods'][0]['params'][0]['schema'];
        $this->assertArrayHasKey('oneOf', $schema);
        $this->assertSame('integer', $schema['oneOf'][0]['type']);
        $this->assertSame('string', $schema['oneOf'][1]['type']);

        $resultSchema = $data['methods'][0]['result']['schema'];
        $this->assertArrayHasKey('oneOf', $resultSchema);
    }

    public function testNullableUnionTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'nullable_union',
                params: [
                    'val' => ['type' => 'int|string|null', 'description' => '', 'required' => false],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $schema = $data['methods'][0]['params'][0]['schema'];
        $this->assertArrayHasKey('oneOf', $schema);
        $nullFound = false;
        foreach ($schema['oneOf'] as $sub) {
            if (isset($sub['type']) && $sub['type'] === 'null') {
                $nullFound = true;
            }
        }
        $this->assertTrue($nullFound, 'Union with null must include null type');
    }

    public function testArrayGenericTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'typed_array',
                params: [
                    'ids' => ['type' => 'array<int>', 'description' => '', 'required' => true],
                ],
                returnType: 'array<string>',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $paramSchema = $data['methods'][0]['params'][0]['schema'];
        $this->assertSame('array', $paramSchema['type']);
        $this->assertSame('integer', $paramSchema['items']['type']);

        $resultSchema = $data['methods'][0]['result']['schema'];
        $this->assertSame('array', $resultSchema['type']);
        $this->assertSame('string', $resultSchema['items']['type']);
    }

    public function testArrayMapTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'map_type',
                params: [
                    'meta' => ['type' => 'array<string, int>', 'description' => '', 'required' => true],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $schema = $data['methods'][0]['params'][0]['schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('additionalProperties', $schema);
        $this->assertSame('integer', $schema['additionalProperties']['type']);
    }

    public function testClassArrayTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'class_array',
                returnType: 'User[]',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $schema = $data['methods'][0]['result']['schema'];
        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
    }

    public function testClassTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'class_type',
                returnType: 'User',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $schema = $data['methods'][0]['result']['schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertSame('User', $schema['description']);
    }

    public function testTagExtractedFromMethodName(): void
    {
        $docs = [
            new MethodDoc(name: 'user.create', description: 'Create user'),
            new MethodDoc(name: 'system.health', description: 'Health check'),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertSame(['name' => 'user'], $data['methods'][0]['tags'][0]);
        $this->assertSame(['name' => 'system'], $data['methods'][1]['tags'][0]);
    }

    public function testNoTagForMethodWithoutDot(): void
    {
        $docs = [
            new MethodDoc(name: 'health', description: 'Health'),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertArrayNotHasKey('tags', $data['methods'][0]);
    }

    public function testExampleRequestAndResponse(): void
    {
        $docs = [
            new MethodDoc(
                name: 'math.add',
                params: [
                    'a' => ['type' => 'int', 'description' => '', 'required' => true],
                    'b' => ['type' => 'int', 'description' => '', 'required' => true],
                ],
                exampleRequest: '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}',
                exampleResponse: '{"jsonrpc":"2.0","result":3,"id":1}',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $examples = $data['methods'][0]['examples'];
        $this->assertNotNull($examples);
        $this->assertCount(2, $examples);

        $requestExample = $examples[0];
        $this->assertSame('request-example', $requestExample['name']);
        $this->assertArrayHasKey('params', $requestExample);

        $responseExample = $examples[1];
        $this->assertSame('response-example', $responseExample['name']);
        $this->assertArrayHasKey('result', $responseExample);
        $this->assertSame(3, $responseExample['result']['value']);
    }

    public function testVoidTypeMapping(): void
    {
        $docs = [
            new MethodDoc(
                name: 'noop',
                returnType: 'void',
            ),
        ];

        $output = $this->generator->generate($docs);
        $data = json_decode($output, true);

        $this->assertSame('null', $data['methods'][0]['result']['schema']['type']);
    }

    public function testOutputIsValidJson(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test',
                description: 'A test method',
                params: [
                    'x' => ['type' => 'int', 'description' => 'value', 'required' => true],
                ],
                returnType: 'string',
                returnDescription: 'result',
                requiresAuth: true,
                errors: [
                    ['code' => -32602, 'description' => 'Bad params'],
                ],
            ),
        ];

        $output = $this->generator->generate($docs);
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $method = $decoded['methods'][0];
        $this->assertTrue($method['params'][0]['required']);
        $this->assertTrue($method['x-requiresAuth']);
        $this->assertSame('result', $method['result']['description']);
        $this->assertSame('Bad params', $method['errors'][0]['message']);
    }
}
