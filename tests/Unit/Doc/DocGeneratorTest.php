<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Doc\MarkdownGenerator;
use Lumen\JsonRpc\Doc\HtmlGenerator;
use Lumen\JsonRpc\Doc\JsonDocGenerator;
use PHPUnit\Framework\TestCase;

final class DocGeneratorTest extends TestCase
{
    private DocGenerator $generator;

    private HandlerRegistry $registry;

    protected function setUp(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
        $this->registry = new HandlerRegistry(
            [$handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $this->registry->discover();
        $this->generator = new DocGenerator($this->registry);
    }

    public function testGeneratesDocsForAllMethods(): void
    {
        $docs = $this->generator->generate();
        $names = array_map(fn($d) => $d->name, $docs);
        $this->assertContains('system.health', $names);
        $this->assertContains('system.version', $names);
        $this->assertContains('user.get', $names);
        $this->assertContains('order.create', $names);
    }

    public function testDescriptionParsed(): void
    {
        $docs = $this->generator->generate();
        $health = array_filter($docs, fn($d) => $d->name === 'system.health');
        $health = reset($health);
        $this->assertNotEmpty($health->description);
        $this->assertStringContainsString('health', strtolower($health->description));
    }

    public function testParamsParsedCorrectly(): void
    {
        $docs = $this->generator->generate();
        $userCreate = array_filter($docs, fn($d) => $d->name === 'user.create');
        $method = reset($userCreate);
        $this->assertNotEmpty($method->params);
        $this->assertArrayHasKey('name', $method->params);
        $this->assertArrayHasKey('email', $method->params);
        $this->assertEquals('string', $method->params['name']['type']);
        $this->assertStringContainsString('full name', $method->params['name']['description']);
    }

    public function testRequiresAuthDetectedFromClass(): void
    {
        $docs = $this->generator->generate();
        $userGet = array_filter($docs, fn($d) => $d->name === 'user.get');
        $userGet = reset($userGet);
        $this->assertTrue($userGet->requiresAuth);
    }

    public function testRequiresNotAuthForSystem(): void
    {
        $docs = $this->generator->generate();
        $health = array_filter($docs, fn($d) => $d->name === 'system.health');
        $health = reset($health);
        $this->assertFalse($health->requiresAuth);
    }

    public function testExampleRequestParsedWithNestedJson(): void
    {
        $docs = $this->generator->generate();
        $userCreate = array_filter($docs, fn($d) => $d->name === 'user.create');
        $method = reset($userCreate);
        $this->assertNotNull($method->exampleRequest);
        $this->assertStringContainsString('user.create', $method->exampleRequest);
    }

    public function testExampleResponseParsed(): void
    {
        $docs = $this->generator->generate();
        $userCreate = array_filter($docs, fn($d) => $d->name === 'user.create');
        $method = reset($userCreate);
        $this->assertNotNull($method->exampleResponse);
    }

    public function testReturnTypeParsed(): void
    {
        $docs = $this->generator->generate();
        $health = array_filter($docs, fn($d) => $d->name === 'system.health');
        $health = reset($health);
        $this->assertNotNull($health->returnType);
    }

    public function testResultSchemaParsedFromDocblockForAutoDiscoveredMethod(): void
    {
        $docs = $this->generator->generate();
        $health = array_filter($docs, fn($d) => $d->name === 'system.health');
        $health = reset($health);

        $this->assertNotNull($health->resultSchema);
        $this->assertSame('object', $health->resultSchema['type']);
        $this->assertFalse($health->resultSchema['additionalProperties']);
        $this->assertSame('string', $health->resultSchema['properties']['status']['type']);
        $this->assertSame('string', $health->resultSchema['properties']['timestamp']['type']);
    }

    public function testMarkdownOutputIsValid(): void
    {
        $docs = $this->generator->generate();
        $md = (new MarkdownGenerator())->generate($docs);
        $this->assertStringContainsString('# ', $md);
        $this->assertStringContainsString('system.health', $md);
        $this->assertStringContainsString('Parameters', $md);
    }

    public function testHtmlOutputIsValid(): void
    {
        $docs = $this->generator->generate();
        $html = (new HtmlGenerator())->generate($docs);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('system.health', $html);
    }

    public function testJsonOutputIsValid(): void
    {
        $docs = $this->generator->generate();
        $json = (new JsonDocGenerator())->generate($docs);
        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertArrayHasKey('methods', $data);
        $this->assertNotEmpty($data['methods']);
    }

    public function testErrorsParsedFromThrows(): void
    {
        $docs = $this->generator->generate();
        $userGet = array_filter($docs, fn($d) => $d->name === 'user.get');
        $method = reset($userGet);
        $this->assertNotEmpty($method->errors);
    }

    public function testSchemaProviderRequestSchemaIsCapturedInDocs(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../tests/Fixtures') ?: __DIR__ . '/../../../tests/Fixtures';
        $registry = new HandlerRegistry(
            [$handlerPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.',
        );
        $registry->discover();

        $docs = (new DocGenerator($registry))->generate();
        $validated = array_values(array_filter($docs, fn($d) => $d->name === 'validatedhandler.create'));

        $this->assertCount(1, $validated);
        $method = $validated[0];
        $this->assertSame('object', $method->requestSchema['type']);
        $this->assertFalse($method->requestSchema['additionalProperties']);
        $this->assertSame('string', $method->params['email']['schema']['type']);
        $this->assertSame(1, $method->params['roles']['schema']['minItems']);
        $this->assertTrue($method->params['email']['required']);
        $this->assertTrue($method->params['roles']['required']);
    }

    public function testNonExistentMethodNotInDocs(): void
    {
        $docs = $this->generator->generate();
        $names = array_map(fn($d) => $d->name, $docs);
        $this->assertNotContains('nonexistent.method', $names);
    }
}
