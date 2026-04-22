<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PackagingReadinessTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';
    }

    public function testComposerJsonIsValid(): void
    {
        $output = [];
        $exitCode = 0;
        exec('composer validate --strict --no-check-version 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, 'composer.json must be valid: ' . implode("\n", $output));
    }

    public function testComposerJsonHasRequiredFields(): void
    {
        $json = file_get_contents($this->rootDir . '/composer.json');
        $data = json_decode($json, true);

        $this->assertNotNull($data);
        $this->assertEquals('larananas/lumen-json-rpc', $data['name']);
        $this->assertEquals('library', $data['type']);
        $this->assertArrayHasKey('license', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('autoload', $data);
        $this->assertArrayHasKey('require', $data);
        $this->assertArrayHasKey('require-dev', $data);
    }

    public function testGitattributesExists(): void
    {
        $this->assertFileExists($this->rootDir . '/.gitattributes');
    }

    public function testGitignoreExists(): void
    {
        $this->assertFileExists($this->rootDir . '/.gitignore');
    }

    public function testLicenseFileExists(): void
    {
        $this->assertFileExists($this->rootDir . '/LICENSE');
    }

    public function testReadmeExists(): void
    {
        $this->assertFileExists($this->rootDir . '/README.md');
    }

    public function testSrcDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->rootDir . '/src');
    }

    public function testExportIgnoreCoversDevArtifacts(): void
    {
        $content = file_get_contents($this->rootDir . '/.gitattributes');

        $exportIgnored = [
            '.gitattributes',
            '.gitignore',
            '.editorconfig',
            '.github/',
            'tests/',
            'examples/',
            'docs/',
            'docs-site/',
            'phpunit.xml',
            'phpstan.neon',
            'infection.json5',
            '.phpactor.json',
            '.phpunit.result.cache',
            'SECURITY.md',
            'CONTRIBUTING.md',
            'CHANGELOG.md',
            '.ai/',
            'bin/verify-package.php',
            'bin/check-coverage-driver.php',
            'bin/check-coverage.php',
            'bin/build-docs-site.php',
            'bin/lint-syntax.php',
        ];

        foreach ($exportIgnored as $item) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($item, '/') . '\s+export-ignore/m',
                $content,
                "{$item} must be export-ignored in .gitattributes"
            );
        }
    }

    public function testGitignoreCoversCacheAndLockFiles(): void
    {
        $content = file_get_contents($this->rootDir . '/.gitignore');

        $ignored = [
            '/vendor/',
            '.phpunit.result.cache',
            'composer.lock',
            'infection-log.txt',
            'dist.tar',
            '/lumen-json-rpc.zip',
        ];

        foreach ($ignored as $item) {
            $this->assertStringContainsString($item, $content,
                "{$item} must be in .gitignore");
        }
    }

    public function testComposerLockIsGitignored(): void
    {
        $gitignore = file_get_contents($this->rootDir . '/.gitignore');
        $this->assertStringContainsString('composer.lock', $gitignore,
            'composer.lock must be gitignored for a library');
    }

    public function testComposerLockIsNotCommitted(): void
    {
        $this->assertFalse(
            $this->isTrackedByGit('composer.lock'),
            'composer.lock must not be committed for this library'
        );
    }

    public function testContributingPhpSupportMatchesComposerAndCi(): void
    {
        $composer = file_get_contents($this->rootDir . '/composer.json');
        $ci = file_get_contents($this->rootDir . '/.github/workflows/ci.yml');
        $contributing = file_get_contents($this->rootDir . '/CONTRIBUTING.md');

        $this->assertStringContainsString('"php": ">=8.2"', $composer);
        $this->assertStringContainsString('php: ["8.2", "8.3", "8.4"]', $ci);
        $this->assertStringContainsString('PHP 8.2–8.4', $contributing);
    }

    public function testContributingDocumentsMutationAsCiJob(): void
    {
        $ci = file_get_contents($this->rootDir . '/.github/workflows/ci.yml');
        $contributing = file_get_contents($this->rootDir . '/CONTRIBUTING.md');

        $this->assertStringNotContainsString('continue-on-error', $ci);
        $this->assertStringContainsString('`mutation` (Infection on PHP 8.3).', $contributing);
        $this->assertStringContainsString('CI must pass (quality + tests on PHP 8.2–8.4 + coverage + mutation).', $contributing);
    }

    public function testQualityDocsExplainCoverageDriverPrerequisites(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');
        $contributing = file_get_contents($this->rootDir . '/CONTRIBUTING.md');
        $phpstan = file_get_contents($this->rootDir . '/phpstan.neon');
        $docsSiteBuilder = file_get_contents($this->rootDir . '/bin/build-docs-site.php');

        $this->assertStringContainsString('`composer qa` runs the standard local release gate', $readme);
        $this->assertStringContainsString('PHPStan level 9', $readme);
        $this->assertStringContainsString('`composer qa:max` extends that local gate with coverage threshold checks and mutation testing', $readme);
        $this->assertStringContainsString('`composer test:coverage` and `composer mutate` require a local coverage driver', $readme);
        $this->assertStringNotContainsString('phpdbg', $readme);
        $this->assertStringContainsString('`quality`, `tests`, `coverage`, and `mutation` jobs', $readme);
        $this->assertStringContainsString('does not commit `composer.lock`', $contributing);
        $this->assertStringContainsString('PHPStan at level 9', $contributing);
        $this->assertStringContainsString('level: 9', $phpstan);
        $this->assertStringContainsString('XDEBUG_MODE=coverage', $contributing);
        $this->assertStringContainsString('or install PCOV', $readme);
        $this->assertStringContainsString('Xdebug coverage mode or PCOV', $contributing);
        $this->assertStringNotContainsString('phpdbg', $contributing);
        $this->assertStringContainsString('requires a local coverage driver', $docsSiteBuilder);
        $this->assertStringContainsString('same coverage-driver requirement', $docsSiteBuilder);
        $this->assertStringContainsString('XDEBUG_MODE=coverage', $docsSiteBuilder);
        $this->assertStringContainsString('Xdebug coverage mode or PCOV', $docsSiteBuilder);
        $this->assertStringNotContainsString('run the command via <code>phpdbg</code>', $docsSiteBuilder);
        $this->assertStringNotContainsString('Xdebug coverage mode, PCOV, or phpdbg', $docsSiteBuilder);
    }

    public function testDocsSiteBuilderReleaseFactsMatchComposerAndCi(): void
    {
        $composer = file_get_contents($this->rootDir . '/composer.json');
        $ci = file_get_contents($this->rootDir . '/.github/workflows/ci.yml');
        $docsSiteBuilder = file_get_contents($this->rootDir . '/bin/build-docs-site.php');

        $this->assertStringContainsString('"php": ">=8.2"', $composer);
        $this->assertStringContainsString('php: ["8.2", "8.3", "8.4"]', $ci);
        $this->assertStringContainsString('<li><strong>PHP &gt;=8.2</strong></li>', $docsSiteBuilder);
        $this->assertStringContainsString('PHP 8.2, 8.3, and 8.4', $docsSiteBuilder);
        $this->assertStringNotContainsString('PHP 8.1+', $docsSiteBuilder);
        $this->assertStringNotContainsString('PHP 8.1, 8.2, 8.3, 8.4', $docsSiteBuilder);
    }

    public function testQualityDocsAvoidClaimingExactLocalCiEquivalence(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');
        $contributing = file_get_contents($this->rootDir . '/CONTRIBUTING.md');
        $docsSiteBuilder = file_get_contents($this->rootDir . '/bin/build-docs-site.php');

        $this->assertStringContainsString('CI covers the same release areas', $readme);
        $this->assertStringContainsString('CI covers the same release areas as `qa:max`', $contributing);
        $this->assertStringContainsString('CI covers the same release areas across parallel jobs', $docsSiteBuilder);
        $this->assertStringNotContainsString('match the full CI bar', $readme);
        $this->assertStringNotContainsString('equivalent of `qa:max`', $contributing);
        $this->assertStringNotContainsString('The commands are identical.', $docsSiteBuilder);
    }

    public function testReadmeDocumentsStableApiBoundary(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');
        $authDocs = file_get_contents($this->rootDir . '/docs/authentication.md');
        $contributing = file_get_contents($this->rootDir . '/CONTRIBUTING.md');

        $this->assertStringContainsString('The stable public surface is centered on `JsonRpcServer`', $readme);
        $this->assertStringContainsString('`JsonRpcServer::getEngine()` is available as an escape hatch', $readme);
        $this->assertStringContainsString('stable server accessors like `getHooks()`, `getRegistry()`, and `getLogger()`', $readme);
        $this->assertStringContainsString('setRequestAuthenticator(new MyRequestAuthenticator())', $readme);
        $this->assertStringContainsString('JsonRpcServer` API', $authDocs);
        $this->assertStringContainsString('setRequestAuthenticator(new class implements RequestAuthenticatorInterface', $authDocs);
        $this->assertStringContainsString('stable server accessors like `getHooks()`, `getRegistry()`, and `getLogger()`', $contributing);
        $this->assertStringContainsString('tests/Integration/StablePublicApiTest.php', $contributing);
        $this->assertStringContainsString('patch releases may fix bugs, docs, tests, packaging, and internal implementation details', $readme);
        $this->assertStringContainsString('major releases may change the documented stable public API', $readme);
        $this->assertStringContainsString('Compatibility-relevant changes are tracked in the [changelog](', $readme);
        $this->assertFileExists($this->rootDir . '/CHANGELOG.md');
    }

    public function testConfigurationReferenceMatchesPathAndHookDefaults(): void
    {
        $defaults = file_get_contents($this->rootDir . '/src/Config/Defaults.php');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');

        $this->assertStringContainsString("'paths' => []", $defaults);
        $this->assertStringContainsString("'protected_methods' => []", $defaults);
        $this->assertStringContainsString("'path' => 'logs/app.log'", $defaults);
        $this->assertStringContainsString("'storage_path' => 'storage/rate_limit'", $defaults);
        $this->assertStringContainsString("'isolate_exceptions' => true", $defaults);

        $this->assertStringContainsString("'paths' => []", $configuration);
        $this->assertStringContainsString("'protected_methods' => []", $configuration);
        $this->assertStringContainsString("'path' => 'logs/app.log'", $configuration);
        $this->assertStringContainsString("'storage_path' => 'storage/rate_limit'", $configuration);
        $this->assertStringContainsString("'isolate_exceptions' => true", $configuration);
        $this->assertStringContainsString('merged defaults', strtolower($configuration));
    }

    public function testCompressionDocsMatchTransportErrorSemantics(): void
    {
        $security = file_get_contents($this->rootDir . '/docs/security.md');
        $server = file_get_contents($this->rootDir . '/src/Server/JsonRpcServer.php');
        $corrections = file_get_contents($this->rootDir . '/tests/Integration/CorrectionTest.php');

        $this->assertStringContainsString('Gzip decompression failures return `-32600 Invalid Request`', $security);
        $this->assertStringNotContainsString('safe parse errors', $security);
        $this->assertStringContainsString("Error::invalidRequest('Request body too large or decompression failed')", $server);
        $this->assertStringContainsString('assertEquals(-32600, $data[\'error\'][\'code\']);', $corrections);
    }

    public function testReadmeDoesNotLinkToExportIgnoredDocsExamplesOrPolicies(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');

        $this->assertDoesNotMatchRegularExpression('/\]\((docs\/|examples\/|\.github\/|CHANGELOG\.md|SECURITY\.md|CONTRIBUTING\.md)/', $readme);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src="\.github\//', $readme);
    }

    public function testDocsCallOutDiscoveryAndHookPolicies(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');
        $architecture = file_get_contents($this->rootDir . '/docs/architecture.md');

        $this->assertStringContainsString('auto-discovery only scans top-level handler files', $readme);
        $this->assertStringContainsString('Hook callbacks run inline.', $readme);
        $this->assertStringContainsString('Auto-discovery scans only the top-level PHP files', $configuration);
        $this->assertStringContainsString('Hook callbacks run inline with request processing.', $configuration);
        $this->assertStringContainsString('top-level handler files in configured paths', $architecture);
        $this->assertStringContainsString('hook exceptions are isolated, logged, and skipped', $architecture);
    }

    public function testDocsCallOutPasswordHashSupportAndSchemaReuse(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');
        $authentication = file_get_contents($this->rootDir . '/docs/authentication.md');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');
        $architecture = file_get_contents($this->rootDir . '/docs/architecture.md');
        $docsSiteBuilder = file_get_contents($this->rootDir . '/bin/build-docs-site.php');

        $this->assertStringContainsString('Generated JSON and OpenRPC docs also reuse these request schemas when available', $readme);
        $this->assertStringContainsString('descriptor metadata can also provide `resultSchema`', $readme);
        $this->assertStringContainsString('docblock tag such as `@result-schema', $readme);
        $this->assertStringContainsString("'password_hash' => password_hash('secret', PASSWORD_DEFAULT)", $readme);
        $this->assertStringContainsString('Prefer `password_hash` for production Basic auth credentials', $authentication);
        $this->assertStringContainsString('`password_hash`', $configuration);
        $this->assertStringContainsString('Runtime request schemas from `RpcSchemaProviderInterface` are also reused by the JSON and OpenRPC generators', $architecture);
        $this->assertStringContainsString('include `resultSchema`', $architecture);
        $this->assertStringContainsString('@result-schema', $architecture);
        $this->assertStringContainsString('Runtime request schemas from <code>RpcSchemaProviderInterface</code>', $docsSiteBuilder);
        $this->assertStringContainsString('descriptor <code>resultSchema</code> metadata', $docsSiteBuilder);
        $this->assertStringContainsString('docblock <code>@result-schema</code> tags', $docsSiteBuilder);
    }

    public function testAuthProtectionDefaultsAndDocsAreConsistent(): void
    {
        $defaults = file_get_contents($this->rootDir . '/src/Config/Defaults.php');
        $readme = file_get_contents($this->rootDir . '/README.md');
        $authentication = file_get_contents($this->rootDir . '/docs/authentication.md');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');
        $basicExample = file_get_contents($this->rootDir . '/examples/basic/config.php');

        $this->assertStringContainsString("'protected_methods' => []", $defaults);
        $this->assertStringContainsString('exact methods or method prefixes', $readme);
        $this->assertStringContainsString('exact method names or trailing-separator prefixes', $authentication);
        $this->assertStringContainsString('Exact names match exactly.', $authentication);
        $this->assertStringContainsString('| `protected_methods` | `[]`', $configuration);
        $this->assertStringContainsString('exact method names or trailing-separator prefixes', $configuration);
        $this->assertStringContainsString("'protected_methods' => []", $basicExample);
    }

    public function testDocsCallOutTransportStatusAndBatchOrderingPolicies(): void
    {
        $readme = file_get_contents($this->rootDir . '/README.md');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');
        $contributing = file_get_contents($this->rootDir . '/CONTRIBUTING.md');

        $this->assertStringContainsString('Mixed valid/invalid batches are not guaranteed to preserve original input order', $readme);
        $this->assertStringContainsString('Allow: POST, GET', $readme);
        $this->assertStringContainsString('representation-specific under gzip negotiation', $readme);
        $this->assertStringContainsString('JSON-RPC parse/protocol/application errors return HTTP `200`', $readme);
        $this->assertStringContainsString('Mixed valid/invalid batches are not guaranteed to preserve input order', $configuration);
        $this->assertStringContainsString('`GET /` is not advertised in the `Allow` header', $configuration);
        $this->assertStringContainsString('ETags are representation-specific', $configuration);
        $this->assertStringContainsString('JSON-RPC parse/protocol/application errors still return HTTP 200', $configuration);
        $this->assertStringContainsString('patch releases may fix internals and packaging', $contributing);
        $this->assertStringContainsString('major releases may break the documented stable surface', $contributing);
    }

    public function testValidationStrictDefaultIsLenientAcrossCodeAndDocs(): void
    {
        $defaults = file_get_contents($this->rootDir . '/src/Config/Defaults.php');
        $server = file_get_contents($this->rootDir . '/src/Server/JsonRpcServer.php');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');
        $basicExample = file_get_contents($this->rootDir . '/examples/basic/config.php');
        $authExample = file_get_contents($this->rootDir . '/examples/auth/config.php');

        $this->assertStringContainsString("'strict' => false", $defaults);
        $this->assertStringContainsString("configBool('validation.strict', false)", $server);
        $this->assertStringContainsString('| `strict`         | `false` |', $configuration);
        $this->assertStringContainsString("'strict' => false", $basicExample);
        $this->assertStringContainsString("'strict' => false", $authExample);
    }

    public function testRateLimitFailClosedDefaultIsConsistentAcrossCodeAndDocs(): void
    {
        $defaults = file_get_contents($this->rootDir . '/src/Config/Defaults.php');
        $server = file_get_contents($this->rootDir . '/src/Server/JsonRpcServer.php');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');
        $security = file_get_contents($this->rootDir . '/docs/security.md');
        $readme = file_get_contents($this->rootDir . '/README.md');
        $basicExample = file_get_contents($this->rootDir . '/examples/basic/config.php');

        $this->assertStringContainsString("'fail_open' => false", $defaults);
        $this->assertStringContainsString("configBool('rate_limit.fail_open', false)", $server);
        $this->assertStringContainsString('| `fail_open`      | `false`', $configuration);
        $this->assertStringContainsString('`fail_open` defaults to `false`', $configuration);
        $this->assertStringContainsString('By default, rate limiting is fail-closed', $readme);
        $this->assertStringContainsString('by default, requests are denied on storage failure', $security);
        $this->assertStringContainsString("'fail_open' => false", $basicExample);
    }

    public function testOpenRpcServerUrlIsOptionalAcrossCodeAndDocs(): void
    {
        $defaults = file_get_contents($this->rootDir . '/src/Config/Defaults.php');
        $generator = file_get_contents($this->rootDir . '/src/Doc/OpenRpcGenerator.php');
        $script = file_get_contents($this->rootDir . '/bin/generate-docs.php');
        $configuration = file_get_contents($this->rootDir . '/docs/configuration.md');

        $this->assertStringContainsString("'url' => ''", $defaults);
        $this->assertStringContainsString("string \$serverUrl = ''", $generator);
        $this->assertStringContainsString("if (\$serverUrl !== '')", $generator);
        $this->assertStringContainsString("configString(\$config, 'server.url', '')", $script);
        $this->assertStringContainsString("| `url`     | `''`", $configuration);
    }

    public function testComposerJsonDoesNotAdvertiseRepoOnlyDocsBuildScript(): void
    {
        $json = json_decode(file_get_contents($this->rootDir . '/composer.json'), true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('scripts', $json);
        $this->assertArrayNotHasKey('docs:build', $json['scripts']);
    }

    public function testDocsGeneratorDoesNotDefaultToExportIgnoredExampleConfig(): void
    {
        $script = file_get_contents($this->rootDir . '/bin/generate-docs.php');

        $this->assertStringNotContainsString('examples/basic/config.php', $script);
        $this->assertStringContainsString('getcwd()', $script);
        $this->assertStringContainsString('Pass --config=PATH', $script);
    }

    public function testDocsDeploymentWorkflowPassesOptionalCustomDomainSecret(): void
    {
        $workflow = file_get_contents($this->rootDir . '/.github/workflows/deploy-docs.yml');

        $this->assertStringContainsString('php bin/build-docs-site.php', $workflow);
        $this->assertStringContainsString('DOCS_CNAME: ${{ secrets.DOCS_CNAME }}', $workflow);
    }

    public function testNoSilentJsonEncodeFallbacksInSource(): void
    {
        $srcDir = $this->rootDir . '/src';
        $this->assertDirectoryExists($srcDir);

        $phpFiles = glob($srcDir . '/**/*.php') ?: [];
        foreach (glob($srcDir . '/*/*.php') ?: [] as $file) {
            $phpFiles[] = $file;
        }
        $phpFiles = array_unique($phpFiles);

        $violations = [];
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if ($file === $this->rootDir . '/src/Log/LogFormatter.php') {
                continue;
            }
            if ($file === $this->rootDir . '/src/Protocol/Response.php') {
                continue;
            }
            if (preg_match('/json_encode\([^)]*\)\s*\?\?/', $content) ||
                preg_match('/json_encode\([^)]*\)\s*\|\|\s*[\'"]/', $content) ||
                preg_match('/json_encode\([^)]*\)\s*\?:/', $content) ||
                preg_match('/json_encode\([^)]*\)\s*===\s*false/', $content) ||
                preg_match('/json_encode\([^)]*\)\s*!==\s*false/', $content)) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations,
            'No silent json_encode fallbacks allowed outside Response and LogFormatter: ' . implode(', ', $violations));
    }

    public function testNoDebugArtifactsInWorkingTree(): void
    {
        $forbidden = [
            '.phpcs-cache',
            'infection-log.txt',
            'coverage.xml',
        ];

        foreach ($forbidden as $file) {
            $this->assertFileDoesNotExist($this->rootDir . '/' . $file,
                "{$file} must not be present in the working tree");
        }
    }

    public function testPhpunitCacheIsGitignored(): void
    {
        $gitignore = file_get_contents($this->rootDir . '/.gitignore');
        $this->assertStringContainsString('.phpunit.result.cache', $gitignore);
    }

    public function testVerifyPackageScriptPasses(): void
    {
        $output = [];
        $exitCode = 0;
        exec('php ' . escapeshellarg($this->rootDir . '/bin/verify-package.php') . ' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, 'verify-package.php must pass: ' . implode("\n", $output));
    }

    public function testRequiredDistFilesPresent(): void
    {
        $required = [
            'composer.json',
            'LICENSE',
            'README.md',
            'bin/generate-docs.php',
            'src/',
        ];

        foreach ($required as $item) {
            $path = $this->rootDir . '/' . $item;
            if (str_ends_with($item, '/')) {
                $this->assertDirectoryExists($path, "{$item} must exist");
            } else {
                $this->assertFileExists($path, "{$item} must exist");
            }
        }
    }

    public function testDistOnlyContainsExpectedFiles(): void
    {
        $gitattributes = file_get_contents($this->rootDir . '/.gitattributes');
        preg_match_all('/^(\S+)\s+export-ignore/m', $gitattributes, $matches);
        $exportIgnored = $matches[1] ?? [];

        $notInDist = ['tests/', '.github/', '.phpunit.result.cache', 'infection.json5', 'phpunit.xml', 'phpstan.neon', 'docs-site/', '.ai/'];
        foreach ($notInDist as $item) {
            $this->assertContains($item, $exportIgnored, "{$item} must be export-ignored");
        }
    }

    public function testComposerJsonAutoLoadPointsToSrc(): void
    {
        $json = json_decode(file_get_contents($this->rootDir . '/composer.json'), true);
        $this->assertArrayHasKey('autoload', $json);
        $this->assertArrayHasKey('psr-4', $json['autoload']);
        $this->assertArrayHasKey('Lumen\\JsonRpc\\', $json['autoload']['psr-4']);
        $this->assertSame('src/', $json['autoload']['psr-4']['Lumen\\JsonRpc\\']);
    }

    private function isTrackedByGit(string $path): bool
    {
        if (!is_dir($this->rootDir . '/.git')) {
            return false;
        }

        $output = [];
        $exitCode = 0;
        exec(
            'git -C ' . escapeshellarg($this->rootDir) . ' ls-files --error-unmatch -- ' . escapeshellarg($path) . ' 2>&1',
            $output,
            $exitCode,
        );

        return $exitCode === 0;
    }
}
