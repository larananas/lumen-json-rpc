<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);

}

$root = dirname(__DIR__);
$failures = 0;
$checked = 0;

$dirs = ['src', 'bin', 'examples'];

foreach ($dirs as $dir) {
    $base = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($base)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $checked++;
        $path = $file->getRealPath();
        if ($path === false) {
            $failures++;
            fwrite(STDERR, "Cannot resolve file path for syntax check.\n");
            continue;
        }

        $output = [];
        $result = null;
        exec(resolvePhpLintBinary() . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $result);

        if ($result !== 0) {
            $failures++;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "No PHP files found to lint.\n");
    exit(1);
}

if ($failures > 0) {
    fwrite(STDERR, "Syntax check failed: {$failures} file(s) with errors out of {$checked} checked.\n");
    exit(1);
}

echo "Syntax check passed: {$checked} file(s) OK.\n";
exit(0);

function resolvePhpLintBinary(): string
{
    if (PHP_SAPI !== 'phpdbg') {
        return escapeshellarg(PHP_BINARY);
    }

    $phpCli = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');
    if (is_file($phpCli)) {
        return escapeshellarg($phpCli);
    }

    return escapeshellarg(PHP_BINARY);
}
