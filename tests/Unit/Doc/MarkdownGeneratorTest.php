<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MarkdownGenerator;
use Lumen\JsonRpc\Doc\MethodDoc;
use PHPUnit\Framework\TestCase;

final class MarkdownGeneratorTest extends TestCase
{
    public function testEmptyDocsOutputsNoMethods(): void
    {
        $generator = new MarkdownGenerator();
        $md = $generator->generate([]);

        $this->assertStringContainsString('No methods documented.', $md);
        $this->assertStringContainsString('# JSON-RPC 2.0 API', $md);
        $this->assertStringNotContainsString('Table of Contents', $md);
    }

    public function testCustomServerName(): void
    {
        $generator = new MarkdownGenerator();
        $md = $generator->generate([], 'My Custom API');

        $this->assertStringContainsString('# My Custom API', $md);
    }

    public function testFullMethodDocRendering(): void
    {
        $doc = new MethodDoc(
            name: 'user.create',
            description: 'Creates a new user account.',
            params: [
                'name' => ['type' => 'string', 'description' => 'Full name', 'required' => true, 'default' => null],
                'email' => ['type' => 'string', 'description' => 'Email address', 'required' => true, 'default' => null],
                'role' => ['type' => 'string', 'description' => 'User role', 'required' => false, 'default' => 'member'],
            ],
            returnType: 'array',
            returnDescription: 'The created user object.',
            requiresAuth: true,
            errors: [
                ['code' => '-32602', 'description' => 'Invalid parameters'],
                ['type' => 'DuplicateEmailException', 'description' => 'Email already exists'],
            ],
            exampleRequest: '{"jsonrpc":"2.0","method":"user.create","params":{"name":"Alice","email":"alice@example.com"},"id":1}',
            exampleResponse: '{"jsonrpc":"2.0","result":{"id":42,"name":"Alice"},"id":1}',
        );

        $generator = new MarkdownGenerator();
        $md = $generator->generate([$doc]);

        $this->assertStringContainsString('## `user.create`', $md);
        $this->assertStringContainsString('Creates a new user account.', $md);
        $this->assertStringContainsString('**Requires Authentication**', $md);
        $this->assertStringContainsString('### Parameters', $md);
        $this->assertStringContainsString('`name`', $md);
        $this->assertStringContainsString('`email`', $md);
        $this->assertStringContainsString('`role`', $md);
        $this->assertStringContainsString('Full name', $md);
        $this->assertStringContainsString('### Returns', $md);
        $this->assertStringContainsString('`array`', $md);
        $this->assertStringContainsString('The created user object.', $md);
        $this->assertStringContainsString('### Errors', $md);
        $this->assertStringContainsString('-32602', $md);
        $this->assertStringContainsString('Invalid parameters', $md);
        $this->assertStringContainsString('DuplicateEmailException', $md);
        $this->assertStringContainsString('Email already exists', $md);
        $this->assertStringContainsString('### Example Request', $md);
        $this->assertStringContainsString('user.create', $md);
        $this->assertStringContainsString('### Example Response', $md);
        $this->assertStringContainsString('"id":42', $md);
        $this->assertStringContainsString('Table of Contents', $md);
    }

    public function testMethodDocWithMinimalFields(): void
    {
        $doc = new MethodDoc(name: 'system.ping');
        $generator = new MarkdownGenerator();
        $md = $generator->generate([$doc]);

        $this->assertStringContainsString('## `system.ping`', $md);
        $this->assertStringNotContainsString('### Parameters', $md);
        $this->assertStringNotContainsString('### Returns', $md);
        $this->assertStringNotContainsString('### Errors', $md);
        $this->assertStringNotContainsString('### Example Request', $md);
        $this->assertStringNotContainsString('### Example Response', $md);
    }

    public function testAuthLockIconInTableOfContents(): void
    {
        $doc = new MethodDoc(name: 'user.delete', requiresAuth: true);
        $generator = new MarkdownGenerator();
        $md = $generator->generate([$doc]);

        $this->assertStringContainsString(':closed_lock_with_key:', $md);
    }

    public function testNoAuthLockIconForPublicMethods(): void
    {
        $doc = new MethodDoc(name: 'system.health', requiresAuth: false);
        $generator = new MarkdownGenerator();
        $md = $generator->generate([$doc]);

        $this->assertStringNotContainsString(':closed_lock_with_key:', $md);
    }
}
