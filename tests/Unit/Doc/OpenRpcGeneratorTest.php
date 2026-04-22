<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcGeneratorTest extends TestCase
{
    public function testGenerateEmptyDocsProducesValidSpec(): void
    {
        $generator = new OpenRpcGenerator();
        $json = $generator->generate([]);
        $spec = json_decode($json, true);

        $this->assertEquals('1.3.2', $spec['openrpc']);
        $this->assertEquals('JSON-RPC 2.0 API', $spec['info']['title']);
        $this->assertEquals('1.0.0', $spec['info']['version']);
        $this->assertEmpty($spec['methods']);
    }

    public function testGenerateWithServerUrl(): void
    {
        $generator = new OpenRpcGenerator();
        $json = $generator->generate([], 'Test API', '2.0.0', 'A description', 'http://localhost:8080');
        $spec = json_decode($json, true);

        $this->assertEquals('Test API', $spec['info']['title']);
        $this->assertEquals('2.0.0', $spec['info']['version']);
        $this->assertEquals('A description', $spec['info']['description']);
        $this->assertArrayHasKey('servers', $spec);
        $this->assertEquals('http://localhost:8080', $spec['servers'][0]['url']);
    }

    public function testGenerateWithoutServerUrlNoServersKey(): void
    {
        $generator = new OpenRpcGenerator();
        $json = $generator->generate([]);
        $spec = json_decode($json, true);
        $this->assertArrayNotHasKey('servers', $spec);
    }

    public function testMethodWithParams(): void
    {
        $doc = new MethodDoc(
            name: 'user.create',
            description: 'Create a user',
            params: [
                'name' => ['type' => 'string', 'description' => 'User name', 'required' => true, 'default' => null],
                'age' => ['type' => 'int', 'description' => 'User age', 'required' => false, 'default' => 18],
            ],
            returnType: 'array',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $method = $spec['methods'][0];
        $this->assertEquals('user.create', $method['name']);
        $this->assertEquals('Create a user', $method['description']);
        $this->assertCount(2, $method['params']);
        $this->assertEquals('name', $method['params'][0]['name']);
        $this->assertTrue($method['params'][0]['required']);
        $this->assertEquals('age', $method['params'][1]['name']);
        $this->assertFalse($method['params'][1]['required']);
    }

    public function testMethodWithTagExtraction(): void
    {
        $doc = new MethodDoc(
            name: 'user.create',
            description: 'Create user',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertArrayHasKey('tags', $spec['methods'][0]);
        $this->assertEquals('user', $spec['methods'][0]['tags'][0]['name']);
    }

    public function testMethodWithoutDotHasNoTag(): void
    {
        $doc = new MethodDoc(name: 'health', description: 'Health check');

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertArrayNotHasKey('tags', $spec['methods'][0]);
    }

    public function testRequiresAuthAddsExtension(): void
    {
        $doc = new MethodDoc(
            name: 'admin.delete',
            description: 'Delete resource',
            requiresAuth: true,
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertTrue($spec['methods'][0]['x-requiresAuth']);
    }

    public function testRequestSchemaAddsParamStructure(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            requestSchema: ['type' => 'object', 'properties' => ['x' => ['type' => 'int']]],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertEquals('by-name', $spec['methods'][0]['paramStructure']);
        $this->assertArrayHasKey('x-jsonrpc-requestSchema', $spec['methods'][0]);
    }

    public function testErrorsWithCodeAndMessage(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            errors: [
                ['code' => -32602, 'message' => 'Invalid params', 'description' => 'Bad input'],
                ['code' => -32601, 'description' => 'Not found'],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $errors = $spec['methods'][0]['errors'];
        $this->assertCount(2, $errors);
        $this->assertEquals(-32602, $errors[0]['code']);
        $this->assertEquals('Invalid params', $errors[0]['message']);
        $this->assertEquals('Not found', $errors[1]['message']);
    }

    public function testErrorsWithCodeAndDifferentDescription(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            errors: [
                ['code' => -32600, 'message' => 'Invalid Request', 'description' => 'Detailed description'],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $error = $spec['methods'][0]['errors'][0];
        $this->assertEquals(-32600, $error['code']);
        $this->assertEquals('Invalid Request', $error['message']);
        $this->assertEquals('Detailed description', $error['data']);
    }

    public function testErrorWithNoMessageUsesDescription(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            errors: [
                ['code' => -32600, 'description' => 'Fallback desc'],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $error = $spec['methods'][0]['errors'][0];
        $this->assertEquals('Fallback desc', $error['message']);
    }

    public function testErrorWithNullCodeSkipped(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            errors: [
                ['description' => 'No code error'],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertArrayNotHasKey('errors', $spec['methods'][0]);
    }

    public function testExampleRequestParsed(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            exampleRequest: '{"jsonrpc":"2.0","method":"test.method","params":{"name":"John"}}',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $examples = $spec['methods'][0]['examples'];
        $this->assertNotEmpty($examples);
        $requestExample = $examples[0];
        $this->assertEquals('request-example', $requestExample['name']);
        $this->assertNotEmpty($requestExample['params']);
    }

    public function testExampleResponseParsed(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            exampleResponse: '{"jsonrpc":"2.0","result":{"status":"ok"},"id":1}',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $examples = $spec['methods'][0]['examples'];
        $responseExample = array_filter($examples, fn($e) => $e['name'] === 'response-example');
        $this->assertNotEmpty($responseExample);
    }

    public function testExampleRequestWithInvalidJsonIgnored(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            exampleRequest: 'not-json',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertArrayNotHasKey('examples', $spec['methods'][0]);
    }

    public function testParamWithSchemaOverridesDefault(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'id' => [
                    'type' => 'int',
                    'description' => 'ID',
                    'required' => true,
                    'default' => null,
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertEquals(['type' => 'integer', 'minimum' => 1], $param['schema']);
    }

    public function testParamWithDefaultWhenOptional(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'limit' => [
                    'type' => 'int',
                    'description' => 'Limit',
                    'required' => false,
                    'default' => 10,
                ],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertEquals(10, $param['schema']['default']);
    }

    public function testReturnTypeToJsonSchema(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            returnType: 'string',
            returnDescription: 'A string result',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $result = $spec['methods'][0]['result'];
        $this->assertEquals('result', $result['name']);
        $this->assertEquals('A string result', $result['description']);
        $this->assertEquals('string', $result['schema']['type']);
    }

    public function testNullableTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'value' => ['type' => '?string', 'description' => '', 'required' => false, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertArrayHasKey('oneOf', $param['schema']);
    }

    public function testUnionTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'value' => ['type' => 'string|int', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertArrayHasKey('oneOf', $param['schema']);
    }

    public function testArrayTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'items' => ['type' => 'array<string>', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertEquals('array', $param['schema']['type']);
        $this->assertEquals('string', $param['schema']['items']['type']);
    }

    public function testArrayMapTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'map' => ['type' => 'array<string, int>', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertEquals('object', $param['schema']['type']);
        $this->assertEquals('integer', $param['schema']['additionalProperties']['type']);
    }

    public function testBoolTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'active' => ['type' => 'bool', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertEquals('boolean', $spec['methods'][0]['params'][0]['schema']['type']);
    }

    public function testFloatTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'price' => ['type' => 'float', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertEquals('number', $spec['methods'][0]['params'][0]['schema']['type']);
    }

    public function testVoidTypeInReturn(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            returnType: 'void',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertEquals('null', $spec['methods'][0]['result']['schema']['type']);
    }

    public function testMixedTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'data' => ['type' => 'mixed', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertArrayHasKey('description', $param['schema']);
    }

    public function testTrueTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            returnType: 'true',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertTrue($spec['methods'][0]['result']['schema']['const']);
    }

    public function testClassArrayTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'items' => ['type' => 'Product[]', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertEquals('array', $param['schema']['type']);
        $this->assertArrayHasKey('items', $param['schema']);
    }

    public function testObjectTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'data' => ['type' => 'object', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $this->assertEquals('object', $spec['methods'][0]['params'][0]['schema']['type']);
    }

    public function testResultSchemaOverridesReturnType(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            returnType: 'string',
            resultSchema: ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $result = $spec['methods'][0]['result'];
        $this->assertEquals('object', $result['schema']['type']);
        $this->assertArrayHasKey('properties', $result['schema']);
    }

    public function testExampleWithPositionalParams(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            exampleRequest: '{"jsonrpc":"2.0","method":"test.method","params":["John",30]}',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $examples = $spec['methods'][0]['examples'];
        $this->assertNotEmpty($examples);
        $this->assertEquals('0', $examples[0]['params'][0]['name']);
        $this->assertEquals('John', $examples[0]['params'][0]['value']);
    }

    public function testCustomClassTypeInParam(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'product' => ['type' => 'App\\Models\\Product', 'description' => '', 'required' => true, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertEquals('object', $param['schema']['type']);
        $this->assertStringContainsString('Product', $param['schema']['description']);
    }

    public function testNullableUnionWithNull(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            params: [
                'val' => ['type' => 'string|null', 'description' => '', 'required' => false, 'default' => null],
            ],
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertArrayHasKey('oneOf', $param['schema']);
        $schemas = $param['schema']['oneOf'];
        $this->assertCount(2, $schemas);
    }

    public function testExampleResponseWithResultKey(): void
    {
        $doc = new MethodDoc(
            name: 'test.method',
            description: 'Test',
            exampleResponse: '{"jsonrpc":"2.0","result":{"status":"ok","id":1},"id":1}',
        );

        $generator = new OpenRpcGenerator();
        $json = $generator->generate([$doc]);
        $spec = json_decode($json, true);

        $examples = $spec['methods'][0]['examples'];
        $respExample = array_values(array_filter($examples, fn($e) => $e['name'] === 'response-example'))[0];
        $this->assertEquals(['status' => 'ok', 'id' => 1], $respExample['result']['value']);
    }
}
