<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
$errors = [];

$gitattributes = file_get_contents($rootDir . '/.gitattributes');
$gitignore = file_get_contents($rootDir . '/.gitignore');

if ($gitattributes === false) {
    fwrite(STDERR, "Cannot read .gitattributes\n");
    exit(1);
}
if ($gitignore === false) {
    fwrite(STDERR, "Cannot read .gitignore\n");
    exit(1);
}

preg_match_all('/^(\S+)\s+export-ignore/m', $gitattributes, $matches);
$exportIgnored = $matches[1];

$mustExportIgnore = [
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

foreach ($mustExportIgnore as $item) {
    if (!in_array($item, $exportIgnored, true)) {
        $errors[] = "Missing export-ignore for: {$item}";
    }
}

$mustGitignore = [
    '/vendor/',
    '.phpunit.result.cache',
    'composer.lock',
    'infection-log.txt',
    'dist.tar',
    '/lumen-json-rpc.zip',
    '.phpactor.json',
];

foreach ($mustGitignore as $item) {
    if (!str_contains($gitignore, $item)) {
        $errors[] = "Missing .gitignore entry for: {$item}";
    }
}

if (isTrackedByGit($rootDir, 'composer.lock')) {
    $errors[] = 'composer.lock must not be committed for this library';
}

$allowedWeakEncodingFiles = [
    'src/Protocol/Response.php',
    'src/Log/LogFormatter.php',
];

$srcDir = $rootDir . '/src';
if (!is_dir($srcDir)) {
    $errors[] = "src/ directory is missing";
} else {
    $phpFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($phpFiles as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $fullPath = (string) str_replace('\\', '/', $file->getPathname());
        $rootNormalized = (string) str_replace('\\', '/', $rootDir) . '/';
        $relativePath = (string) str_replace($rootNormalized, '', $fullPath);

        if (in_array($relativePath, $allowedWeakEncodingFiles, true)) {
            continue;
        }

        if (preg_match('/json_encode\([^)]*\)\s*\?\?/', $content)) {
            $errors[] = "Silent json_encode ?? fallback in {$relativePath} (only allowed in Response.php and LogFormatter.php)";
        }

        if (preg_match('/json_encode\([^)]*\)\s*\?:/', $content)) {
            $errors[] = "Silent json_encode ?: fallback in {$relativePath} (only allowed in Response.php and LogFormatter.php)";
        }

        if (preg_match('/\$encoded\s*=\s*json_encode\([^)]*\)\s*;.*if\s*\(\s*\$encoded\s*===\s*false\s*\)/s', $content)) {
            $errors[] = "Explicit false-check json_encode fallback in {$relativePath} (only allowed in Response.php and LogFormatter.php)";
        }

        if (preg_match('/json_encode\([^)]*\)\s*===\s*false/', $content)) {
            $errors[] = "json_encode === false comparison in {$relativePath} (only allowed in Response.php and LogFormatter.php)";
        }

        if (preg_match('/json_encode\([^)]*\)\s*!==\s*false/', $content)) {
            $errors[] = "json_encode !== false comparison in {$relativePath} (only allowed in Response.php and LogFormatter.php)";
        }
    }
}

$requiredFiles = [
    'composer.json',
    'LICENSE',
    'README.md',
    '.gitattributes',
    '.gitignore',
    'bin/generate-docs.php',
    'src/',
];

foreach ($requiredFiles as $file) {
    $path = $rootDir . '/' . $file;
    if (str_ends_with($file, '/')) {
        if (!is_dir($path)) {
            $errors[] = "Required directory missing: {$file}";
        }
    } else {
        if (!file_exists($path)) {
            $errors[] = "Required file missing: {$file}";
        }
    }
}

/** @var list<string> $declaredBins */
$declaredBins = [];

$composerContent = file_get_contents($rootDir . '/composer.json');
if ($composerContent === false) {
    $errors[] = "Cannot read composer.json";
} else {
    $composerJson = json_decode($composerContent, true);
    if (!is_array($composerJson)) {
        $errors[] = "composer.json is not valid JSON";
    } else {
        $requiredComposerKeys = ['name', 'description', 'type', 'license', 'require', 'autoload'];
        foreach ($requiredComposerKeys as $key) {
            if (!isset($composerJson[$key])) {
                $errors[] = "composer.json missing required key: {$key}";
            }
        }

        if (($composerJson['type'] ?? '') !== 'library') {
            $errors[] = "composer.json type must be 'library'";
        }

        if (isset($composerJson['scripts']['docs:build'])) {
            $errors[] = "composer.json must not advertise docs:build because the docs-site builder is export-ignored";
        }

        if (!file_exists($rootDir . '/CHANGELOG.md')) {
            $errors[] = 'CHANGELOG.md must exist because README advertises a changelog';
        }

        $rawBins = $composerJson['bin'] ?? [];
        if (!is_array($rawBins)) {
            $errors[] = 'composer.json bin must be an array when present';
            $rawBins = [];
        }

        foreach ($rawBins as $binPath) {
            if (!is_string($binPath) || $binPath === '') {
                $errors[] = 'composer.json bin entries must be non-empty strings';
                continue;
            }

            $declaredBins[] = $binPath;

            if (!file_exists($rootDir . '/' . $binPath)) {
                $errors[] = "composer.json bin target is missing: {$binPath}";
            }

            if (in_array($binPath, $exportIgnored, true)) {
                $errors[] = "composer.json bin target must not be export-ignored: {$binPath}";
            }
        }
    }
}

verifyReadmePackageLinks($rootDir, $errors);
verifyDocsGeneratorDefaults($rootDir, $errors);

$junkFiles = ['.phpcs-cache'];
foreach ($junkFiles as $junk) {
    if (file_exists($rootDir . '/' . $junk)) {
        $errors[] = "Junk file present in working tree: {$junk}";
    }
}

$archiveErrors = verifyArchive($rootDir, $declaredBins);
$errors = array_merge($errors, $archiveErrors);

if (!empty($errors)) {
    fwrite(STDERR, "PACKAGING ERRORS:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  X {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Packaging verification passed.\n");
exit(0);

/**
 * Build a git archive and inspect its actual contents.
 * This catches issues that .gitattributes/config heuristics miss.
 *
 * @param list<string> $declaredBins
 * @return array<int, string>
 */
function verifyArchive(string $rootDir, array $declaredBins): array
{
    $errors = [];

    $isGitRepo = is_dir($rootDir . '/.git');
    if (!$isGitRepo) {
        $errors[] = "ARCHIVE: Not a git repository — cannot build archive for inspection";
        return $errors;
    }

    $tmpDir = sys_get_temp_dir() . '/lumen-jsonrpc-pkg-verify-' . bin2hex(random_bytes(4));
    $archiveFile = $tmpDir . '.tar';

    @mkdir($tmpDir, 0755, true);

    try {
        $archiveTreeish = resolveArchiveTreeish($rootDir, $errors);
        if ($archiveTreeish === null) {
            return $errors;
        }

        exec(
            'git -C ' . escapeshellarg($rootDir)
            . ' archive --worktree-attributes --format=tar --output=' . escapeshellarg($archiveFile)
            . ' ' . escapeshellarg($archiveTreeish) . ' 2>&1',
            $output,
            $exitCode,
        );
        if ($exitCode !== 0 || !file_exists($archiveFile)) {
            $errors[] = "ARCHIVE: Failed to create git archive: " . implode("\n", $output);
            return $errors;
        }

        exec('tar -xf ' . escapeshellarg($archiveFile) . ' -C ' . escapeshellarg($tmpDir) . ' 2>&1', $extractOutput, $extractExit);
        if ($extractExit !== 0) {
            $errors[] = "ARCHIVE: Failed to extract archive: " . implode("\n", $extractOutput);
            return $errors;
        }

        $forbiddenInArchive = [
            '.git',
            '.github',
            'tests',
            'phpunit.xml',
            'phpstan.neon',
            'phpstan.neon.dist',
            'infection.json5',
            '.phpunit.result.cache',
            '.phpactor.json',
            'CONTRIBUTING.md',
            'CHANGELOG.md',
            'composer.lock',
            'SECURITY.md',
            '.ai',
            'IMPLEMENTATION_REPORT.md',
            'bin/verify-package.php',
            'bin/check-coverage-driver.php',
            'bin/check-coverage.php',
            'bin/build-docs-site.php',
            'bin/lint-syntax.php',
            'docs',
            'docs-site',
            'examples',
        ];

        foreach ($forbiddenInArchive as $forbidden) {
            if (file_exists($tmpDir . '/' . $forbidden) || is_dir($tmpDir . '/' . $forbidden)) {
                $errors[] = "ARCHIVE: Forbidden file/directory present in package: {$forbidden}";
            }
        }

        $mustBeInArchive = [
            'composer.json',
            'LICENSE',
            'README.md',
            'src/',
        ];

        foreach ($declaredBins as $binPath) {
            $mustBeInArchive[] = $binPath;
        }

        foreach ($mustBeInArchive as $required) {
            if (!file_exists($tmpDir . '/' . $required)) {
                $errors[] = "ARCHIVE: Required file/directory missing from package: {$required}";
            }
        }

        $srcDir = $tmpDir . '/src';
        if (is_dir($srcDir)) {
            $srcFiles = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $phpCount = 0;
            foreach ($srcFiles as $f) {
                if (!$f instanceof SplFileInfo) {
                    continue;
                }

                if ($f->isFile() && $f->getExtension() === 'php') {
                    $phpCount++;
                }
            }
            if ($phpCount === 0) {
                $errors[] = "ARCHIVE: No PHP source files found in src/";
            }
        }

        $archiveComposerContent = @file_get_contents($tmpDir . '/composer.json');
        if ($archiveComposerContent !== false) {
            $archiveComposer = json_decode($archiveComposerContent, true);
            if (!is_array($archiveComposer)) {
                $errors[] = "ARCHIVE: composer.json in archive is not valid JSON";
            } elseif (($archiveComposer['type'] ?? '') !== 'library') {
                $errors[] = "ARCHIVE: composer.json type must be 'library'";
            }
        }

        fwrite(STDOUT, "  Archive inspection: " . count($errors) . " archive issues found\n");
    } finally {
        $cleanupFiles = [$archiveFile];
        $cleanupDirs = [$tmpDir];
        foreach ($cleanupFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        foreach (array_reverse($cleanupDirs) as $d) {
            if (is_dir($d)) {
                removeDir($d);
            }
        }
    }

    return $errors;
}

/**
 * @param array<int, string> $errors
 */
function resolveArchiveTreeish(string $rootDir, array &$errors): ?string
{
    $output = [];
    $exitCode = 0;
    exec('git -C ' . escapeshellarg($rootDir) . ' stash create "package-verify" 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        $errors[] = 'ARCHIVE: Failed to create tracked worktree snapshot: ' . implode("\n", $output);
        return null;
    }

    $treeish = trim(implode("\n", $output));
    if ($treeish !== '') {
        return $treeish;
    }

    $headOutput = [];
    $headExitCode = 0;
    exec('git -C ' . escapeshellarg($rootDir) . ' rev-parse HEAD 2>&1', $headOutput, $headExitCode);
    if ($headExitCode !== 0) {
        $errors[] = 'ARCHIVE: Failed to resolve HEAD for archive verification: ' . implode("\n", $headOutput);
        return null;
    }

    $head = trim(implode("\n", $headOutput));
    if ($head === '') {
        $errors[] = 'ARCHIVE: Could not determine an archive treeish';
        return null;
    }

    return $head;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * @param array<int, string> $errors
 */
function verifyReadmePackageLinks(string $rootDir, array &$errors): void
{
    $readme = @file_get_contents($rootDir . '/README.md');
    if ($readme === false) {
        $errors[] = 'Cannot read README.md';
        return;
    }

    if (preg_match('/\]\((docs\/|examples\/|\.github\/|CHANGELOG\.md|SECURITY\.md|CONTRIBUTING\.md)/', $readme) === 1) {
        $errors[] = 'README.md must not contain package-broken relative links to export-ignored docs, examples, or policies';
    }

    if (preg_match('/<img[^>]+src="\.github\//', $readme) === 1) {
        $errors[] = 'README.md must not embed images from export-ignored .github assets';
    }
}

/**
 * @param array<int, string> $errors
 */
function verifyDocsGeneratorDefaults(string $rootDir, array &$errors): void
{
    $script = @file_get_contents($rootDir . '/bin/generate-docs.php');
    if ($script === false) {
        $errors[] = 'Cannot read bin/generate-docs.php';
        return;
    }

    if (str_contains($script, '/../examples/')) {
        $errors[] = 'bin/generate-docs.php must not default to an export-ignored example config';
    }
}

function isTrackedByGit(string $rootDir, string $path): bool
{
    if (!is_dir($rootDir . '/.git')) {
        return false;
    }

    $output = [];
    $exitCode = 0;
    exec(
        'git -C ' . escapeshellarg($rootDir) . ' ls-files --error-unmatch -- ' . escapeshellarg($path) . ' 2>&1',
        $output,
        $exitCode,
    );

    return $exitCode === 0;
}
