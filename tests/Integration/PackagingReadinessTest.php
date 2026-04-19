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
            'phpunit.xml',
            'phpstan.neon',
            'infection.json5',
            '.phpactor.json',
            '.phpunit.result.cache',
            'SECURITY.md',
            'CONTRIBUTING.md',
            'CHANGELOG.md',
            'bin/verify-package.php',
            'bin/check-coverage.php',
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

        $notInDist = ['tests/', '.github/', '.phpunit.result.cache', 'infection.json5', 'phpunit.xml', 'phpstan.neon'];
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
}
