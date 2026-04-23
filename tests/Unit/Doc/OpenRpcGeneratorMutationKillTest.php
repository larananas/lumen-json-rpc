<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcGeneratorMutationKillTest extends TestCase
{
    private OpenRpcGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new OpenRpcGenerator();
    }

    public function testEmptyDocsGeneratesValidSpec(): void
    {
        $json = $this->generator->generate([]);
        $spec = json_decode($json, true);
        $this->assertSame('1.3.2', $spec['openrpc']);
        $this->assertSame([], $spec['methods']);
    }

    public function testJsonFlagsPreserveUnicodeAndSlashes(): void
    {
        $doc = new MethodDoc(
            name: 'test.unicode',
            description: 'Test with unicode: é à ü',
            params: [],
        );
        $json = $this->generator->generate([$doc], 'Serveur/API', '2.0.0');
        $this->assertStringContainsString('Serveur/API', $json);
        $this->assertStringContainsString('é', $json);
        $this->assertStringNotContainsString('\u00e9', $json);
    }

    public function testParamStructureByNamedForObjectSchema(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            requestSchema: ['type' => 'object', 'properties' => []],
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('by-name', $spec['methods'][0]['paramStructure']);
    }

    public function testParamStructureNotSetForNonObjectSchema(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            requestSchema: ['type' => 'array'],
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertArrayNotHasKey('paramStructure', $spec['methods'][0]);
    }

    public function testExampleRequestWithAssociativeParams(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            exampleRequest: '{"jsonrpc":"2.0","method":"test","params":{"name":"John","age":30},"id":1}',
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $examples = $spec['methods'][0]['examples'];
        $this->assertCount(1, $examples);
        $params = $examples[0]['params'];
        $paramNames = array_column($params, 'name');
        $this->assertContains('name', $paramNames);
        $this->assertContains('age', $paramNames);
    }

    public function testExampleRequestWithIndexedParams(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            exampleRequest: '{"jsonrpc":"2.0","method":"test","params":[1,2,3],"id":1}',
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $params = $spec['methods'][0]['examples'][0]['params'];
        $this->assertSame('0', $params[0]['name']);
        $this->assertSame(1, $params[0]['value']);
    }

    public function testExampleResponseDecodedCorrectly(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            exampleResponse: '{"result":{"status":"ok"},"id":1}',
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $examples = $spec['methods'][0]['examples'];
        $this->assertCount(1, $examples);
        $this->assertSame('response-example', $examples[0]['name']);
        $this->assertSame(['status' => 'ok'], $examples[0]['result']['value']);
    }

    public function testInvalidJsonExampleRequestSkipped(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            exampleRequest: 'not valid json',
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertArrayNotHasKey('examples', $spec['methods'][0]);
    }

    public function testParamSchemaOverrideUsed(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [
                'name' => [
                    'type' => 'string',
                    'description' => 'The name',
                    'required' => true,
                    'default' => null,
                    'schema' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $param = $spec['methods'][0]['params'][0];
        $this->assertSame(1, $param['schema']['minLength']);
    }

    public function testParamDefaultAppliedWhenNotRequired(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [
                'limit' => [
                    'type' => 'int',
                    'description' => 'Limit',
                    'required' => false,
                    'default' => 10,
                ],
            ],
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $param = $spec['methods'][0]['params'][0];
        $this->assertSame(10, $param['schema']['default']);
    }

    public function testParamDefaultNotAppliedWhenRequired(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name',
                    'required' => true,
                    'default' => 'fallback',
                ],
            ],
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $param = $spec['methods'][0]['params'][0];
        $this->assertArrayNotHasKey('default', $param['schema']);
    }

    public function testPhpTypeMappingInteger(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'val' => ['type' => 'integer', 'description' => '', 'required' => true, 'default' => null],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('integer', $spec['methods'][0]['params'][0]['schema']['type']);
    }

    public function testPhpTypeMappingFloat(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'val' => ['type' => 'float', 'description' => '', 'required' => true, 'default' => null],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('number', $spec['methods'][0]['params'][0]['schema']['type']);
    }

    public function testPhpTypeMappingBool(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'val' => ['type' => 'boolean', 'description' => '', 'required' => true, 'default' => null],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('boolean', $spec['methods'][0]['params'][0]['schema']['type']);
    }

    public function testPhpTypeMappingTrueConst(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'true');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertTrue($spec['methods'][0]['result']['schema']['const']);
    }

    public function testPhpTypeMappingFalseConst(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'false');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertFalse($spec['methods'][0]['result']['schema']['const']);
    }

    public function testPhpTypeMappingVoid(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'void');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('null', $spec['methods'][0]['result']['schema']['type']);
    }

    public function testPhpTypeMappingObject(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'object');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('object', $spec['methods'][0]['result']['schema']['type']);
    }

    public function testPhpTypeMappingMixed(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'mixed');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('Any value', $spec['methods'][0]['result']['schema']['description']);
    }

    public function testPhpTypeArrayGeneric(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'array<string>');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $resultSchema = $spec['methods'][0]['result']['schema'];
        $this->assertSame('array', $resultSchema['type']);
        $this->assertSame('string', $resultSchema['items']['type']);
    }

    public function testPhpTypeArrayMap(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'array<string,int>');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $resultSchema = $spec['methods'][0]['result']['schema'];
        $this->assertSame('object', $resultSchema['type']);
        $this->assertSame('integer', $resultSchema['additionalProperties']['type']);
    }

    public function testPhpTypeClassArray(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'User[]');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $resultSchema = $spec['methods'][0]['result']['schema'];
        $this->assertSame('array', $resultSchema['type']);
        $this->assertSame('object', $resultSchema['items']['type']);
        $this->assertSame('User', $resultSchema['items']['description']);
    }

    public function testPhpTypeClassDefault(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'DateTime');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $resultSchema = $spec['methods'][0]['result']['schema'];
        $this->assertSame('object', $resultSchema['type']);
        $this->assertSame('DateTime', $resultSchema['description']);
    }

    public function testNullableTypeCreatesOneOfWithNull(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: '?string');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $schema = $spec['methods'][0]['result']['schema'];
        $this->assertArrayHasKey('oneOf', $schema);
        $types = array_column($schema['oneOf'], 'type');
        $this->assertContains('string', $types);
        $this->assertContains('null', $types);
    }

    public function testUnionTypeIntOrString(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'int|string');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $schema = $spec['methods'][0]['result']['schema'];
        $this->assertArrayHasKey('oneOf', $schema);
        $this->assertCount(2, $schema['oneOf']);
        $types = array_column($schema['oneOf'], 'type');
        $this->assertContains('integer', $types);
        $this->assertContains('string', $types);
    }

    public function testUnionTypeWithNull(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'string|null');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $schema = $spec['methods'][0]['result']['schema'];
        $this->assertArrayHasKey('oneOf', $schema);
        $this->assertCount(2, $schema['oneOf']);
        $this->assertSame('string', $schema['oneOf'][0]['type']);
        $this->assertSame('null', $schema['oneOf'][1]['type']);
    }

    public function testErrorCodeIntCast(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['code' => '32001', 'message' => 'Auth required', 'description' => 'desc'],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame(32001, $spec['methods'][0]['errors'][0]['code']);
        $this->assertSame('Auth required', $spec['methods'][0]['errors'][0]['message']);
    }

    public function testErrorCodeNonNumericDefaultsToZero(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['code' => 'INVALID', 'message' => 'Bad', 'description' => 'desc'],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame(0, $spec['methods'][0]['errors'][0]['code']);
    }

    public function testErrorDescriptionInDataWhenDifferent(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['code' => '32001', 'message' => 'Auth required', 'description' => 'Full auth description'],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('Full auth description', $spec['methods'][0]['errors'][0]['data']);
    }

    public function testErrorDescriptionNotInDataWhenSame(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['code' => '32001', 'message' => 'Auth required', 'description' => 'Auth required'],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertArrayNotHasKey('data', $spec['methods'][0]['errors'][0]);
    }

    public function testErrorMessageFallsBackToDescription(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['code' => '32001', 'description' => 'Fallback message'],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('Fallback message', $spec['methods'][0]['errors'][0]['message']);
    }

    public function testTagExtractedFromDottedName(): void
    {
        $doc = new MethodDoc(name: 'user.create', params: []);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('user', $spec['methods'][0]['tags'][0]['name']);
    }

    public function testNoTagForNonDottedName(): void
    {
        $doc = new MethodDoc(name: 'create', params: []);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertArrayNotHasKey('tags', $spec['methods'][0]);
    }

    public function testAuthExtension(): void
    {
        $doc = new MethodDoc(name: 'test', params: [], requiresAuth: true);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertTrue($spec['methods'][0]['x-requiresAuth']);
    }

    public function testResultSchemaOverride(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            returnType: 'string',
            resultSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('object', $spec['methods'][0]['result']['schema']['type']);
    }

    public function testIsAssociativeDetectsStringKeys(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            exampleRequest: '{"params":{"key":"val"}}',
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $params = $spec['methods'][0]['examples'][0]['params'];
        $this->assertSame('key', $params[0]['name']);
    }

    public function testIsAssociativeDetectsIndexedKeys(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            params: [],
            exampleRequest: '{"params":["a","b"]}',
        );
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $params = $spec['methods'][0]['examples'][0]['params'];
        $this->assertSame('0', $params[0]['name']);
        $this->assertSame('a', $params[0]['value']);
    }

    public function testParamDescriptionFromParam(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'val' => ['type' => 'string', 'description' => 'My param desc', 'required' => true, 'default' => null],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('My param desc', $spec['methods'][0]['params'][0]['description']);
    }

    public function testParamRequiredFalseWhenNotTrue(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'val' => ['type' => 'string', 'description' => '', 'required' => false, 'default' => null],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertFalse($spec['methods'][0]['params'][0]['required']);
    }

    public function testReturnDescriptionUsed(): void
    {
        $doc = new MethodDoc(name: 'test', returnDescription: 'The returned data');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('The returned data', $spec['methods'][0]['result']['description']);
    }

    public function testErrorWithoutCodeNotIncluded(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['type' => 'Exception', 'description' => 'Some error'],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertArrayNotHasKey('errors', $spec['methods'][0]);
    }

    public function testServerUrlIncludedWhenProvided(): void
    {
        $json = $this->generator->generate([], 'My API', '1.0.0', '', 'http://localhost:8080');
        $spec = json_decode($json, true);
        $this->assertSame('http://localhost:8080', $spec['servers'][0]['url']);
        $this->assertSame('My API', $spec['servers'][0]['name']);
    }

    public function testPhpTypeArrayNoGeneric(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'array');
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $schema = $spec['methods'][0]['result']['schema'];
        $this->assertSame('array', $schema['type']);
        $this->assertArrayHasKey('items', $schema);
        $this->assertSame('Any value', $schema['items']['description']);
    }

    public function testPhpTypeDoubleMapsToNumber(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'val' => ['type' => 'double', 'description' => '', 'required' => true, 'default' => null],
        ]);
        $json = $this->generator->generate([$doc]);
        $spec = json_decode($json, true);
        $this->assertSame('number', $spec['methods'][0]['params'][0]['schema']['type']);
    }
}
