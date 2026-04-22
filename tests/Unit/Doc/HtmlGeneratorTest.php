<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\HtmlGenerator;
use Lumen\JsonRpc\Doc\MethodDoc;
use PHPUnit\Framework\TestCase;

final class HtmlGeneratorTest extends TestCase
{
    public function testEmptyDocsOutputsNoMethods(): void
    {
        $generator = new HtmlGenerator();
        $html = $generator->generate([]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
        $this->assertStringContainsString(
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
            $html,
        );
        $this->assertStringContainsString('Auto-generated API documentation.', $html);
        $this->assertStringNotContainsString('Table of Contents', $html);
        $this->assertStringNotContainsString('<ul class="toc">', $html);
        $this->assertStringContainsString('</body>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testCustomServerNameInTitleAndHeading(): void
    {
        $generator = new HtmlGenerator();
        $html = $generator->generate([], 'My API Service');

        $this->assertStringContainsString('<title>My API Service</title>', $html);
        $this->assertStringContainsString('<h1>My API Service</h1>', $html);
    }

    public function testDefaultServerName(): void
    {
        $generator = new HtmlGenerator();
        $html = $generator->generate([]);

        $this->assertStringContainsString('<title>JSON-RPC 2.0 API</title>', $html);
        $this->assertStringContainsString('<h1>JSON-RPC 2.0 API</h1>', $html);
    }

    public function testTableOfContentsWithMethods(): void
    {
        $docs = [
            new MethodDoc(name: 'System.Health'),
            new MethodDoc(name: 'user.create', requiresAuth: true),
        ];
        $generator = new HtmlGenerator();
        $html = $generator->generate($docs);

        $this->assertStringContainsString('<h2>Table of Contents</h2>', $html);
        $this->assertStringContainsString('<ul class="toc">', $html);
        $this->assertStringContainsString('href="#system-health"', $html);
        $this->assertStringContainsString('System.Health', $html);
        $this->assertStringContainsString('Auth Required', $html);
        $this->assertStringContainsString('</ul>', $html);
        $this->assertStringContainsString('<hr>', $html);
    }

    public function testTableOfContentsNoAuthBadgeForPublicMethods(): void
    {
        $doc = new MethodDoc(name: 'system.health');
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $tocSection = substr($html, 0, strpos($html, '</ul>') + 5);
        $this->assertStringNotContainsString('Auth Required', $tocSection);
    }

    public function testFullMethodDocRendering(): void
    {
        $doc = new MethodDoc(
            name: 'User.Create',
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
            exampleRequest: '{"jsonrpc":"2.0","method":"User.Create","params":{"name":"Alice"},"id":1}',
            exampleResponse: '{"jsonrpc":"2.0","result":{"id":42},"id":1}',
        );

        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('id="user-create"', $html);
        $this->assertStringContainsString('<code>User.Create</code>', $html);
        $this->assertStringContainsString('Creates a new user account.', $html);
        $this->assertStringContainsString('Requires Authentication', $html);
        $this->assertStringContainsString('<h3>Parameters</h3>', $html);
        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('<th>Type</th>', $html);
        $this->assertStringContainsString('<th>Required</th>', $html);
        $this->assertStringContainsString('<th>Description</th>', $html);
        $this->assertStringContainsString('<code>name</code>', $html);
        $this->assertStringContainsString('<code>email</code>', $html);
        $this->assertStringContainsString('<code>role</code>', $html);
        $this->assertStringContainsString('Full name', $html);
        $this->assertStringContainsString('Email address', $html);
        $this->assertStringContainsString('User role', $html);
        $this->assertStringContainsString('>Yes<', $html);
        $this->assertStringContainsString('>No<', $html);
        $this->assertStringContainsString('<h3>Returns</h3>', $html);
        $this->assertStringContainsString('<code>array</code>', $html);
        $this->assertStringContainsString(' — The created user object.', $html);
        $this->assertStringContainsString('<h3>Errors</h3>', $html);
        $this->assertStringContainsString('<strong>-32602</strong>', $html);
        $this->assertStringContainsString('Invalid parameters', $html);
        $this->assertStringContainsString('DuplicateEmailException', $html);
        $this->assertStringContainsString('Email already exists', $html);
        $this->assertStringContainsString('<h3>Example Request</h3>', $html);
        $this->assertStringContainsString('User.Create', $html);
        $this->assertStringContainsString('<h3>Example Response</h3>', $html);
        $this->assertStringContainsString('&quot;id&quot;:42', $html);
    }

    public function testMinimalMethodDocNoOptionalSections(): void
    {
        $doc = new MethodDoc(name: 'system.ping');
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('<h2 id="system-ping"><code>system.ping</code></h2>', $html);
        $this->assertStringNotContainsString('<h3>Parameters</h3>', $html);
        $this->assertStringNotContainsString('<h3>Returns</h3>', $html);
        $this->assertStringNotContainsString('<h3>Errors</h3>', $html);
        $this->assertStringNotContainsString('<h3>Example Request</h3>', $html);
        $this->assertStringNotContainsString('<h3>Example Response</h3>', $html);
    }

    public function testPublicMethodNoAuthBadge(): void
    {
        $doc = new MethodDoc(name: 'system.health', requiresAuth: false);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringNotContainsString('Auth Required', $html);
        $this->assertStringNotContainsString('Requires Authentication', $html);
    }

    public function testAnchorIdLowercasesAndReplacesDotsAndSpaces(): void
    {
        $doc = new MethodDoc(name: 'Module.Sub Action');
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('id="module-sub-action"', $html);
        $this->assertStringContainsString('href="#module-sub-action"', $html);
    }

    public function testXssProtectionInServerName(): void
    {
        $generator = new HtmlGenerator();
        $html = $generator->generate([], '<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testXssProtectionInDescription(): void
    {
        $doc = new MethodDoc(name: 'test', description: '<b>bold</b>');
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
    }

    public function testXssProtectionInParams(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            '<img>' => ['type' => 'string<evil>', 'description' => '<script>x</script>', 'required' => true, 'default' => null],
        ]);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('&lt;img&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
    }

    public function testXssProtectionInErrors(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['code' => '<xss>', 'description' => '<script>err</script>'],
        ]);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('&lt;xss&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;err&lt;/script&gt;', $html);
    }

    public function testXssProtectionInExamples(): void
    {
        $doc = new MethodDoc(
            name: 'test',
            exampleRequest: '<script>req</script>',
            exampleResponse: '<script>res</script>',
        );
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('&lt;script&gt;req&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;res&lt;/script&gt;', $html);
    }

    public function testErrorLabelFallbackToTypeWhenNoCode(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['type' => 'CustomError', 'description' => 'Something failed'],
        ]);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('<strong>CustomError</strong>', $html);
        $this->assertStringContainsString('Something failed', $html);
    }

    public function testErrorLabelFallbackToEmptyWhenNoCodeOrType(): void
    {
        $doc = new MethodDoc(name: 'test', errors: [
            ['description' => 'Unknown error'],
        ]);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('<strong></strong>', $html);
        $this->assertStringContainsString('Unknown error', $html);
    }

    public function testReturnTypeOnlyNoDescription(): void
    {
        $doc = new MethodDoc(name: 'test', returnType: 'string', returnDescription: '');
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertStringContainsString('<code>string</code>', $html);
        $this->assertStringNotContainsString(' — ', $html);
    }

    public function testCssStylesIncluded(): void
    {
        $generator = new HtmlGenerator();
        $html = $generator->generate([]);

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('font-family', $html);
        $this->assertStringContainsString('.auth-required', $html);
        $this->assertStringContainsString('.toc', $html);
        $this->assertStringContainsString('</style>', $html);
    }

    public function testParamRequiredShowsYes(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'required_param' => ['type' => 'int', 'description' => 'req', 'required' => true, 'default' => null],
        ]);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertMatchesRegularExpression('/<td>Yes<\/td>/', $html);
    }

    public function testParamOptionalShowsNo(): void
    {
        $doc = new MethodDoc(name: 'test', params: [
            'optional_param' => ['type' => 'int', 'description' => 'opt', 'required' => false, 'default' => null],
        ]);
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertMatchesRegularExpression('/<td>No<\/td>/', $html);
    }

    public function testMethodSeparatorHrRendered(): void
    {
        $doc = new MethodDoc(name: 'test');
        $generator = new HtmlGenerator();
        $html = $generator->generate([$doc]);

        $this->assertMatchesRegularExpression('/<hr(?:\s*\/)?>\s*<\/body>/', $html);
    }
}
