<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if (extension_loaded('pcov')) {
    fwrite(STDOUT, "Coverage driver ready: PCOV.\n");
    exit(0);
}

if (extension_loaded('xdebug')) {
    $xdebugMode = getenv('XDEBUG_MODE');
    if (!is_string($xdebugMode) || $xdebugMode === '') {
        $configuredMode = ini_get('xdebug.mode');
        $xdebugMode = is_string($configuredMode) ? $configuredMode : '';
    }

    $modes = array_map('trim', explode(',', $xdebugMode));
    if (in_array('coverage', $modes, true)) {
        fwrite(STDOUT, "Coverage driver ready: Xdebug coverage mode.\n");
        exit(0);
    }

    fwrite(
        STDERR,
        "Xdebug is loaded but coverage mode is disabled. Run with XDEBUG_MODE=coverage, enable xdebug.mode=coverage, or install PCOV.\n",
    );
    exit(1);
}

fwrite(
    STDERR,
    "No coverage driver available. Enable Xdebug coverage mode or install PCOV.\n",
);
exit(1);
