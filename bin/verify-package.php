<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
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
    'docs-site-src/',
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
    '.phpactor.json',
];

foreach ($mustGitignore as $item) {
    if (!str_contains($gitignore, $item)) {
        $errors[] = "Missing .gitignore entry for: {$item}";
    }
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

$composerContent = file_get_contents($rootDir . '/composer.json');
if ($composerContent === false) {
    $errors[] = "Cannot read composer.json";
} else {
    $composerJson = json_decode($composerContent, true);
    if ($composerJson === null) {
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
    }
}

$junkFiles = ['.phpcs-cache'];
foreach ($junkFiles as $junk) {
    if (file_exists($rootDir . '/' . $junk)) {
        $errors[] = "Junk file present in working tree: {$junk}";
    }
}

$archiveErrors = verifyArchive($rootDir);
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
 * @return array<int, string>
 */
function verifyArchive(string $rootDir): array
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
        exec('cd ' . escapeshellarg($rootDir) . ' && git archive HEAD --output=' . escapeshellarg($archiveFile) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0 || !file_exists($archiveFile)) {
            $errors[] = "ARCHIVE: Failed to create git archive: " . implode("\n", $output);
            return $errors;
        }

        exec('cd ' . escapeshellarg($tmpDir) . ' && tar xf ' . escapeshellarg($archiveFile) . ' 2>&1', $extractOutput, $extractExit);
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
            'composer.lock',
            'SECURITY.md',
            '.ai',
            'IMPLEMENTATION_REPORT.md',
            'bin/verify-package.php',
            'bin/check-coverage.php',
            'bin/build-docs-site.php',
            'bin/lint-syntax.php',
            'docs',
            'docs-site-src',
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
            if ($archiveComposer === null) {
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
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}
