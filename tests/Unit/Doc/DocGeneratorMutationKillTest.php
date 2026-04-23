<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;
use PHPUnit\Framework\TestCase;

final class DocGeneratorMutationKillTest extends TestCase
{
    private function createTempHandler(string $namespace, string $className, string $body): string
    {
        $dir = sys_get_temp_dir() . '/lumen_doc_test_' . uniqid();
        mkdir($dir);
        $file = $dir . '/' . $className . '.php';
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$body}");
        return $file;
    }

    private function cleanupTempDir(string $file): void
    {
        $dir = dirname($file);
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($dir) && str_contains($dir, 'lumen_doc_test_')) {
            @rmdir($dir);
        }
    }

    public function testClassLoadedFromFileWhenNotAutoloaded(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'FromFileHandler',
            <<<'PHP'
class FromFileHandler
{
    public function ping(): string
    {
        return 'pong';
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'fromfilehandler.ping') {
                $found = true;
                $this->assertSame('fromfilehandler.ping', $doc->name);
            }
        }
        $this->assertTrue($found, 'Expected to find fromfilehandler.ping method');
        $this->cleanupTempDir($file);
    }

    public function testSchemaProviderExtractsRequestSchema(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'SchemaDocHandler',
            <<<'PHP'
class SchemaDocHandler implements \Lumen\JsonRpc\Validation\RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'create' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'description' => 'The name'],
                ],
            ],
        ];
    }

    /**
     * Create something.
     * @param string $name The name
     * @return array result
     */
    public function create(string $name): array
    {
        return ['name' => $name];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'schemadochandler.create') {
                $found = true;
                $this->assertNotNull($doc->requestSchema);
                $this->assertSame('object', $doc->requestSchema['type']);
                $this->assertArrayHasKey('name', $doc->params);
                $this->assertSame('The name', $doc->params['name']['description']);
                $this->assertTrue($doc->params['name']['required']);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testRequestContextParameterSkipped(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'ContextHandler',
            <<<'PHP'
use Lumen\JsonRpc\Support\RequestContext;

class ContextHandler
{
    /**
     * Do something.
     * @param string $name The name
     * @param RequestContext $context The context
     * @return array result
     */
    public function execute(string $name, RequestContext $context): array
    {
        return ['name' => $name];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'contexthandler.execute') {
                $found = true;
                $this->assertArrayHasKey('name', $doc->params);
                $this->assertArrayNotHasKey('context', $doc->params);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testNullableParameterType(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'NullableHandler',
            <<<'PHP'
class NullableHandler
{
    /**
     * Do something.
     * @param ?string $name The name
     * @return array result
     */
    public function execute(?string $name): array
    {
        return ['name' => $name];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'nullablehandler.execute') {
                $found = true;
                $this->assertSame('?string', $doc->params['name']['type']);
                $this->assertFalse($doc->params['name']['required']);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testReturnTagPriorityOverReflection(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'ReturnHandler',
            <<<'PHP'
class ReturnHandler
{
    /**
     * Do something.
     * @return string[] list of things
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'returnhandler.execute') {
                $found = true;
                $this->assertSame('string[]', $doc->returnType);
                $this->assertSame('list of things', $doc->returnDescription);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testAuthenticatedTagDetected(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'AuthTagHandler',
            <<<'PHP'
/**
 * Handler requiring auth.
 * @authenticated
 */
class AuthTagHandler
{
    /**
     * Do something.
     * @return array result
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'authtaghandler.execute') {
                $found = true;
                $this->assertTrue($doc->requiresAuth);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testAuthRequiredTagDetected(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'AuthReqHandler',
            <<<'PHP'
class AuthReqHandler
{
    /**
     * Do something.
     * @auth required
     * @return array result
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'authreqhandler.execute') {
                $found = true;
                $this->assertTrue($doc->requiresAuth);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testThrowsAndErrorTagsParsed(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'ErrorTagHandler',
            <<<'PHP'
class ErrorTagHandler
{
    /**
     * Do something.
     * @throws RuntimeException when runtime fails
     * @error INVALID_PARAM when param is bad
     * @return array result
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'errortaghandler.execute') {
                $found = true;
                $throwsFound = false;
                $errorFound = false;
                foreach ($doc->errors as $error) {
                    if (isset($error['type']) && str_contains($error['type'], 'RuntimeException')) {
                        $throwsFound = true;
                        $this->assertSame('when runtime fails', $error['description']);
                    }
                    if (isset($error['code']) && $error['code'] === 'INVALID_PARAM') {
                        $errorFound = true;
                        $this->assertSame('when param is bad', $error['description']);
                    }
                }
                $this->assertTrue($throwsFound, 'Expected @throws tag parsed');
                $this->assertTrue($errorFound, 'Expected @error tag parsed');
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testDescriptionStopsAtAtTag(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'DescHandler',
            <<<'PHP'
class DescHandler
{
    /**
     * First line of description.
     * Second line.
     * @param string $name The name
     * @return array result
     */
    public function execute(string $name): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'deschandler.execute') {
                $found = true;
                $this->assertStringContainsString('First line', $doc->description);
                $this->assertStringContainsString('Second line', $doc->description);
                $this->assertStringNotContainsString('@param', $doc->description);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testJsonTagWithNestedBraces(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'JsonTagHandler',
            <<<'PHP'
class JsonTagHandler
{
    /**
     * Do something.
     * @example-request {"jsonrpc": "2.0", "method": "test", "params": {"nested": {"deep": true}}, "id": 1}
     * @example-response {"result": {"nested": {"deep": true}}, "id": 1}
     * @result-schema {"type": "object", "properties": {"result": {"type": "object"}}}
     * @return array result
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'jsontaghandler.execute') {
                $found = true;
                $this->assertNotNull($doc->exampleRequest);
                $this->assertStringContainsString('"nested"', $doc->exampleRequest);
                $this->assertNotNull($doc->exampleResponse);
                $this->assertStringContainsString('"nested"', $doc->exampleResponse);
                $this->assertNotNull($doc->resultSchema);
                $this->assertSame('object', $doc->resultSchema['type']);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testDescriptorMetadataTakesPriorityOverReflection(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            /**
             * Reflection description.
             * @return string
             */
            public function execute(): string
            {
                return 'test';
            }
        };

        $className = get_class($handlerClass);
        $registry->register('test.method', $className, 'execute', [
            'description' => 'Descriptor description',
            'returnType' => 'array',
            'requiresAuth' => true,
            'errors' => [
                ['type' => 'TestException', 'description' => 'test error'],
            ],
            'exampleRequest' => '{"test": true}',
            'exampleResponse' => '{"ok": true}',
            'resultSchema' => ['type' => 'object'],
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs);
        $doc = $docs[0];
        $this->assertSame('Descriptor description', $doc->description);
        $this->assertSame('array', $doc->returnType);
        $this->assertTrue($doc->requiresAuth);
        $this->assertCount(1, $doc->errors);
        $this->assertSame('TestException', $doc->errors[0]['type']);
        $this->assertSame('{"test": true}', $doc->exampleRequest);
        $this->assertSame('{"ok": true}', $doc->exampleResponse);
        $this->assertSame('object', $doc->resultSchema['type']);
    }

    public function testMetadataBoolWithNonBoolReturnsDefault(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            public function execute(): void {}
        };

        $registry->register('test.bool', get_class($handlerClass), 'execute', [
            'requiresAuth' => 'yes',
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertFalse($docs[0]->requiresAuth);
    }

    public function testMetadataErrorsWithIntCode(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            public function execute(): void {}
        };

        $registry->register('test.errors', get_class($handlerClass), 'execute', [
            'errors' => [
                ['code' => 123, 'description' => 'Error with int code'],
                ['code' => 'ABC', 'description' => 'Error with string code'],
                ['message' => 'Error with message', 'description' => 'desc'],
            ],
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(3, $docs[0]->errors);
        $this->assertSame('123', $docs[0]->errors[0]['code']);
        $this->assertSame('ABC', $docs[0]->errors[1]['code']);
        $this->assertSame('Error with message', $docs[0]->errors[2]['message']);
    }

    public function testMetadataErrorsSkipsEmptyDescription(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            public function execute(): void {}
        };

        $registry->register('test.empty', get_class($handlerClass), 'execute', [
            'errors' => [
                ['type' => 'TestError', 'description' => ''],
                ['type' => 'GoodError', 'description' => 'has desc'],
            ],
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs[0]->errors);
        $this->assertSame('GoodError', $docs[0]->errors[0]['type']);
    }

    public function testMetadataSchemaReturnsNullForNonArray(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            public function execute(): void {}
        };

        $registry->register('test.schema', get_class($handlerClass), 'execute', [
            'resultSchema' => 'not an array',
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertNull($docs[0]->resultSchema);
    }

    public function testMetadataParamsWithSchemaAndInvalidEntries(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            public function execute(): void {}
        };

        $registry->register('test.params', get_class($handlerClass), 'execute', [
            'params' => [
                'valid' => ['type' => 'string', 'description' => 'desc', 'required' => true, 'schema' => ['minLength' => 1]],
                123 => 'invalid key',
                'no_type' => ['description' => 'no type'],
            ],
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $params = $docs[0]->params;
        $this->assertArrayHasKey('valid', $params);
        $this->assertSame('string', $params['valid']['type']);
        $this->assertTrue($params['valid']['required']);
        $this->assertArrayHasKey('schema', $params['valid']);
        $this->assertArrayHasKey('no_type', $params);
        $this->assertSame('mixed', $params['no_type']['type']);
        $this->assertFalse($params['no_type']['required']);
        $this->assertArrayNotHasKey(123, $params);
    }

    public function testMergeSchemaDescriptionFillsEmptyParamDescription(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');

        $handlerClass = new class {
            public function execute(string $name): array
            {
                return [];
            }
        };

        $registry->register('test.merge', get_class($handlerClass), 'execute', [
            'params' => [
                'name' => [
                    'type' => 'string',
                    'description' => '',
                    'required' => true,
                    'default' => null,
                ],
            ],
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs);
        $this->assertSame('', $docs[0]->params['name']['description']);
    }

    public function testNonObjectSchemaReturnsParamsUnchanged(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'NonObjectSchemaHandler',
            <<<'PHP'
class NonObjectSchemaHandler implements \Lumen\JsonRpc\Validation\RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'execute' => ['type' => 'string'],
        ];
    }

    /**
     * @param string $name The name
     * @return string
     */
    public function execute(string $name): string
    {
        return $name;
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'nonobjectschemahandler.execute') {
                $found = true;
                $this->assertSame('The name', $doc->params['name']['description']);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testEmptyDescriptionFromEmptyDoc(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'NoDocHandler',
            <<<'PHP'
class NoDocHandler
{
    public function execute(): void {}
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'nodochandler.execute') {
                $found = true;
                $this->assertSame('', $doc->description);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testReturnTypeFallsBackToReflection(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'ReflectReturnHandler',
            <<<'PHP'
class ReflectReturnHandler
{
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'reflectreturnhandler.execute') {
                $found = true;
                $this->assertSame('array', $doc->returnType);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testNonExistentClassProducesDocFromDescriptor(): void
    {
        $registry = new HandlerRegistry([], 'Test\\', '.');
        $registry->register('ghost.method', 'NonExistentClass', 'execute', [
            'description' => 'Ghost method',
            'returnType' => 'void',
        ]);

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs);
        $this->assertSame('ghost.method', $docs[0]->name);
        $this->assertSame('Ghost method', $docs[0]->description);
        $this->assertSame('void', $docs[0]->returnType);
    }

    public function testJsonTagWithArraySyntax(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'ArrayJsonHandler',
            <<<'PHP'
class ArrayJsonHandler
{
    /**
     * @example-request ["jsonrpc", "2.0", "test"]
     * @example-response ["result", "ok"]
     * @return array
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'arrayjsonhandler.execute') {
                $found = true;
                $this->assertNotNull($doc->exampleRequest);
                $this->assertStringStartsWith('[', $doc->exampleRequest);
                $this->assertNotNull($doc->exampleResponse);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testParamWithNoTypeReturnsMixed(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'NoTypeHandler',
            <<<'PHP'
class NoTypeHandler
{
    /**
     * @param $data The data
     * @return mixed
     */
    public function execute($data): mixed
    {
        return $data;
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'notypehandler.execute') {
                $found = true;
                $this->assertSame('mixed', $doc->params['data']['type']);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testParamWithDefaultAndNullableNotRequired(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'DefaultNullableHandler',
            <<<'PHP'
class DefaultNullableHandler
{
    /**
     * @param ?string $name The name
     * @return array
     */
    public function execute(?string $name = null): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'defaultnullablehandler.execute') {
                $found = true;
                $this->assertFalse($doc->params['name']['required']);
                $this->assertNull($doc->params['name']['default']);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }

    public function testMultipleErrorsFromDocComment(): void
    {
        $file = $this->createTempHandler(
            'Test\\Handlers',
            'MultiErrorHandler',
            <<<'PHP'
class MultiErrorHandler
{
    /**
     * @throws InvalidArgumentException bad input
     * @throws RuntimeException runtime issue
     * @error CODE_A first error
     * @error CODE_B second error
     * @return array
     */
    public function execute(): array
    {
        return [];
    }
}
PHP
        );

        $dir = dirname($file);
        $registry = new HandlerRegistry([$dir], 'Test\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $found = false;
        foreach ($docs as $doc) {
            if ($doc->name === 'multierrorhandler.execute') {
                $found = true;
                $this->assertCount(4, $doc->errors);

                $types = array_map(function ($e) {
                    return str_replace('\\', '', $e['type'] ?? '');
                }, $doc->errors);
                $this->assertContains('InvalidArgumentException', $types);
                $this->assertContains('RuntimeException', $types);

                $codes = array_column($doc->errors, 'code');
                $this->assertContains('CODE_A', $codes);
                $this->assertContains('CODE_B', $codes);
            }
        }
        $this->assertTrue($found);
        $this->cleanupTempDir($file);
    }
}
