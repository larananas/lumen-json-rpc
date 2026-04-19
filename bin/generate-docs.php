<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
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
$configPath = is_string($options['config'] ?? null) ? $options['config'] : __DIR__ . '/../examples/basic/config.php';

if (isset($options['help'])) {
    echo "JSON-RPC Documentation Generator\n\n";
    echo "Usage: php bin/generate-docs.php [options]\n\n";
    echo "Options:\n";
    echo "  --format=FORMAT    Output format: markdown, json, html, openrpc (default: markdown)\n";
    echo "  --output=PATH      Output file path (default: stdout)\n";
    echo "  --config=PATH      Config file path\n";
    echo "  --help             Show this help\n";
    exit(0);
}

$config = Config::fromFile($configPath);

$registry = new HandlerRegistry(
    $config->get('handlers.paths', []),
    $config->get('handlers.namespace', 'App\\Handlers\\'),
    $config->get('handlers.method_separator', '.'),
);
$registry->discover();

$generator = new DocGenerator($registry);
$docs = $generator->generate();

$serverName = $config->get('server.name', 'JSON-RPC 2.0 API');

$output = match ($format) {
    'json' => (new JsonDocGenerator())->generate($docs, $serverName),
    'html' => (new HtmlGenerator())->generate($docs, $serverName),
    'openrpc' => (new OpenRpcGenerator())->generate(
        $docs,
        $serverName,
        $config->get('server.version', '1.0.0'),
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
