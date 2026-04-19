<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
$cloverPath = $rootDir . '/coverage.xml';

if (!file_exists($cloverPath)) {
    fwrite(STDERR, "coverage.xml not found. Run `composer test:coverage` with XDebug or PCOV enabled first.\n");
    exit(1);
}

$minLineCoverage = 80;
$minMethodCoverage = 75;

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse coverage.xml.\n");
    exit(1);
}

$metrics = $xml->xpath('//project/metrics');
if (empty($metrics)) {
    fwrite(STDERR, "No project metrics found in coverage.xml.\n");
    exit(1);
}

$projectMetrics = $metrics[0];
$totalElements = (int)$projectMetrics['elements'];
$coveredElements = (int)$projectMetrics['coveredelements'];
$totalMethods = (int)$projectMetrics['methods'];
$coveredMethods = (int)$projectMetrics['coveredmethods'];
$totalLines = (int)($projectMetrics['statements'] ?? 0);
$coveredLines = (int)($projectMetrics['coveredstatements'] ?? 0);

$errors = [];

if ($totalElements > 0) {
    $elementCoverage = ($coveredElements / $totalElements) * 100;
    fwrite(STDOUT, sprintf("Element coverage: %.1f%% (%d/%d)\n", $elementCoverage, $coveredElements, $totalElements));
    if ($elementCoverage < $minLineCoverage) {
        $errors[] = sprintf("Element coverage %.1f%% is below threshold %d%%", $elementCoverage, $minLineCoverage);
    }
}

if ($totalMethods > 0) {
    $methodCoverage = ($coveredMethods / $totalMethods) * 100;
    fwrite(STDOUT, sprintf("Method coverage: %.1f%% (%d/%d)\n", $methodCoverage, $coveredMethods, $totalMethods));
    if ($methodCoverage < $minMethodCoverage) {
        $errors[] = sprintf("Method coverage %.1f%% is below threshold %d%%", $methodCoverage, $minMethodCoverage);
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "\nCOVERAGE THRESHOLD FAILURES:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  X {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Coverage thresholds met.\n");
exit(0);
