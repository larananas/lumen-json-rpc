<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use JsonSchema\Validator;
use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcFormalMetaSchemaTest extends TestCase
{
    private static ?object $metaSchema = null;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(Validator::class)) {
            return;
        }

        $pinnedPath = dirname(__DIR__, 2) . '/Fixtures/openrpc-schema-1.3.2.json';
        if (!file_exists($pinnedPath)) {
            return;
        }

        $json = file_get_contents($pinnedPath);
        if ($json === false) {
            return;
        }

        self::$metaSchema = json_decode($json, false);
    }

    public function testFormalMetaSchemaValidationOnComprehensiveOutput(): void
    {
        if (self::$metaSchema === null) {
            $this->markTestSkipped('justinrainbow/json-schema not available or pinned meta-schema fixture missing.');
        }

        $generator = new OpenRpcGenerator();
        $docs = $this->createComprehensiveDocs();
        $output = $generator->generate($docs, 'Test API', '1.0.0', 'A test API');
        $spec = json_decode($output, false);

        $validator = new Validator();
        $validator->validate($spec, self::$metaSchema);

        $errors = array_map(
            static fn(array $e): string => "[{$e['property']}] {$e['message']}",
            $validator->getErrors(),
        );

        $this->assertEmpty(
            $errors,
            "Generated OpenRPC document must pass formal meta-schema validation.\n"
            . "Violations:\n" . implode("\n", $errors),
        );
    }

    public function testFormalMetaSchemaValidationOnMinimalOutput(): void
    {
        if (self::$metaSchema === null) {
            $this->markTestSkipped('justinrainbow/json-schema not available or pinned meta-schema fixture missing.');
        }

        $generator = new OpenRpcGenerator();
        $output = $generator->generate([], 'Minimal API', '0.1.0');
        $spec = json_decode($output, false);

        $validator = new Validator();
        $validator->validate($spec, self::$metaSchema);

        $errors = array_map(
            static fn(array $e): string => "[{$e['property']}] {$e['message']}",
            $validator->getErrors(),
        );

        $this->assertEmpty(
            $errors,
            "Minimal OpenRPC document must pass formal meta-schema validation.\n"
            . "Violations:\n" . implode("\n", $errors),
        );
    }

    public function testFormalMetaSchemaValidationOnSingleMethodOutput(): void
    {
        if (self::$metaSchema === null) {
            $this->markTestSkipped('justinrainbow/json-schema not available or pinned meta-schema fixture missing.');
        }

        $generator = new OpenRpcGenerator();
        $docs = [
            new MethodDoc(
                name: 'ping',
                description: 'Simple ping method',
                params: [],
                returnType: 'string',
                returnDescription: 'Pong',
                requiresAuth: false,
            ),
        ];
        $output = $generator->generate($docs, 'Ping API', '1.0.0');
        $spec = json_decode($output, false);

        $validator = new Validator();
        $validator->validate($spec, self::$metaSchema);

        $errors = array_map(
            static fn(array $e): string => "[{$e['property']}] {$e['message']}",
            $validator->getErrors(),
        );

        $this->assertEmpty(
            $errors,
            "Single-method OpenRPC document must pass formal meta-schema validation.\n"
            . "Violations:\n" . implode("\n", $errors),
        );
    }

    public function testFormalMetaSchemaValidationOnAllTypeVariants(): void
    {
        if (self::$metaSchema === null) {
            $this->markTestSkipped('justinrainbow/json-schema not available or pinned meta-schema fixture missing.');
        }

        $generator = new OpenRpcGenerator();
        $docs = [
            new MethodDoc(
                name: 'test.allTypes',
                description: 'Tests every PHP type mapping',
                params: [
                    'a' => ['type' => 'int', 'description' => 'Int', 'required' => true, 'default' => null],
                    'b' => ['type' => 'float', 'description' => 'Float', 'required' => true, 'default' => null],
                    'c' => ['type' => 'bool', 'description' => 'Bool', 'required' => true, 'default' => null],
                    'd' => ['type' => 'string', 'description' => 'String', 'required' => true, 'default' => null],
                    'e' => ['type' => 'array', 'description' => 'Array', 'required' => false, 'default' => null],
                    'f' => ['type' => 'object', 'description' => 'Object', 'required' => false, 'default' => null],
                    'g' => ['type' => 'mixed', 'description' => 'Mixed', 'required' => false, 'default' => null],
                    'h' => ['type' => 'void', 'description' => 'Void', 'required' => false, 'default' => null],
                    'i' => ['type' => 'null', 'description' => 'Null', 'required' => false, 'default' => null],
                    'j' => ['type' => '?string', 'description' => 'Nullable', 'required' => false, 'default' => null],
                    'k' => ['type' => 'int|string', 'description' => 'Union', 'required' => false, 'default' => null],
                    'l' => ['type' => 'array<int>', 'description' => 'Generic array', 'required' => false, 'default' => null],
                    'm' => ['type' => 'array<string,int>', 'description' => 'Map', 'required' => false, 'default' => null],
                    'n' => ['type' => 'User[]', 'description' => 'Class array', 'required' => false, 'default' => null],
                    'o' => ['type' => 'true', 'description' => 'True literal', 'required' => false, 'default' => null],
                    'p' => ['type' => 'false', 'description' => 'False literal', 'required' => false, 'default' => null],
                ],
                returnType: 'array<string, mixed>',
                returnDescription: 'Result map',
                requiresAuth: true,
                errors: [
                    ['code' => -32602, 'message' => 'Invalid params', 'description' => 'Bad input'],
                ],
            ),
        ];
        $output = $generator->generate($docs, 'Type Variant API', '2.0.0', 'All types');
        $spec = json_decode($output, false);

        $validator = new Validator();
        $validator->validate($spec, self::$metaSchema);

        $errors = array_map(
            static fn(array $e): string => "[{$e['property']}] {$e['message']}",
            $validator->getErrors(),
        );

        $this->assertEmpty(
            $errors,
            "All-type-variant OpenRPC document must pass formal meta-schema validation.\n"
            . "Violations:\n" . implode("\n", $errors),
        );
    }

    public function testFormalMetaSchemaValidationOnOutputWithExamples(): void
    {
        if (self::$metaSchema === null) {
            $this->markTestSkipped('justinrainbow/json-schema not available or pinned meta-schema fixture missing.');
        }

        $generator = new OpenRpcGenerator();
        $docs = [
            new MethodDoc(
                name: 'math.add',
                description: 'Add two numbers',
                params: [
                    'a' => ['type' => 'int', 'description' => 'First operand', 'required' => true, 'default' => null],
                    'b' => ['type' => 'int', 'description' => 'Second operand', 'required' => true, 'default' => null],
                ],
                returnType: 'int',
                returnDescription: 'Sum',
                requiresAuth: false,
                exampleRequest: '{"a": 2, "b": 3}',
                exampleResponse: '{"result": 5}',
            ),
        ];
        $output = $generator->generate($docs, 'Math API', '1.0.0');
        $spec = json_decode($output, false);

        $validator = new Validator();
        $validator->validate($spec, self::$metaSchema);

        $errors = array_map(
            static fn(array $e): string => "[{$e['property']}] {$e['message']}",
            $validator->getErrors(),
        );

        $this->assertEmpty(
            $errors,
            "OpenRPC document with examples must pass formal meta-schema validation.\n"
            . "Violations:\n" . implode("\n", $errors),
        );
    }

    /**
     * @return array<int, MethodDoc>
     */
    private function createComprehensiveDocs(): array
    {
        return [
            new MethodDoc(
                name: 'user.create',
                description: 'Create a new user',
                params: [
                    'name' => ['type' => 'string', 'description' => 'User name', 'required' => true, 'default' => null],
                    'email' => ['type' => 'string', 'description' => 'Email', 'required' => true, 'default' => null],
                    'role' => ['type' => 'string', 'description' => 'Role', 'required' => false, 'default' => 'user'],
                ],
                returnType: 'array',
                returnDescription: 'Created user object',
                requiresAuth: true,
                errors: [
                    ['code' => -32602, 'message' => 'Invalid params', 'description' => 'Parameters failed validation'],
                    ['code' => -32001, 'message' => 'Auth required', 'description' => 'Authentication required'],
                ],
            ),
            new MethodDoc(
                name: 'system.health',
                description: 'Health check',
                params: [],
                returnType: 'object',
                returnDescription: 'Health status',
                requiresAuth: false,
            ),
        ];
    }
}
