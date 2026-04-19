<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
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
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $checked++;
        $path = $file->getRealPath();
        $output = [];
        $result = null;
        exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $result);

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
