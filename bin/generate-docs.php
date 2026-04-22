<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Doc\MarkdownGenerator;
use Lumen\JsonRpc\Doc\JsonDocGenerator;
use Lumen\JsonRpc\Doc\HtmlGenerator;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;

$options = getopt('', ['format:', 'output:', 'config:', 'help']);
$format = is_string($options['format'] ?? null) ? $options['format'] : 'markdown';
$outputPath = is_string($options['output'] ?? null) ? $options['output'] : null;

if (isset($options['help'])) {
    echo "JSON-RPC Documentation Generator\n\n";
    echo "Usage: php bin/generate-docs.php [options]\n\n";
    echo "Options:\n";
    echo "  --format=FORMAT    Output format: markdown, json, html, openrpc (default: markdown)\n";
    echo "  --output=PATH      Output file path (default: stdout)\n";
    echo "  --config=PATH      Config file path\n";
    echo "                     Default discovery: ./config.php, ./json-rpc.php, ./config/json-rpc.php\n";
    echo "  --help             Show this help\n";
    exit(0);
}

$configPath = resolveConfigPath(is_string($options['config'] ?? null) ? $options['config'] : null);

$config = Config::fromFile($configPath);

$registry = new HandlerRegistry(
    configStringList($config, 'handlers.paths', []),
    configString($config, 'handlers.namespace', 'App\\Handlers\\'),
    configString($config, 'handlers.method_separator', '.'),
);
$registry->discover();

$generator = new DocGenerator($registry);
$docs = $generator->generate();

$serverName = configString($config, 'server.name', 'JSON-RPC 2.0 API');

$output = match ($format) {
    'json' => (new JsonDocGenerator())->generate($docs, $serverName),
    'html' => (new HtmlGenerator())->generate($docs, $serverName),
    'openrpc' => (new OpenRpcGenerator())->generate(
        $docs,
        $serverName,
        configString($config, 'server.version', '1.0.0'),
        '',
        configString($config, 'server.url', ''),
    ),
    default => (new MarkdownGenerator())->generate($docs, $serverName),
};

if ($outputPath !== null) {
    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($outputPath, $output);
    echo "Documentation written to: $outputPath\n";
} else {
    echo $output;
}

function resolveConfigPath(?string $configuredPath): string
{
    if (is_string($configuredPath) && $configuredPath !== '') {
        return $configuredPath;
    }

    $cwd = getcwd();
    if ($cwd !== false) {
        $candidates = [
            $cwd . '/config.php',
            $cwd . '/json-rpc.php',
            $cwd . '/config/json-rpc.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    fwrite(STDERR, "No config file found. Pass --config=PATH or place config.php, json-rpc.php, or config/json-rpc.php in the current working directory.\n");
    exit(1);
}

function configString(Config $config, string $key, string $default): string
{
    $value = $config->get($key, $default);
    if (!is_string($value)) {
        fwrite(STDERR, "Config key {$key} must be a string.\n");
        exit(1);
    }

    return $value;
}

/**
 * @param list<string> $default
 * @return list<string>
 */
function configStringList(Config $config, string $key, array $default): array
{
    $value = $config->get($key, $default);
    if (!is_array($value)) {
        fwrite(STDERR, "Config key {$key} must be an array of strings.\n");
        exit(1);
    }

    $strings = [];
    foreach ($value as $item) {
        if (!is_string($item) || $item === '') {
            fwrite(STDERR, "Config key {$key} must be an array of non-empty strings.\n");
            exit(1);
        }

        $strings[] = $item;
    }

    return $strings;
}
