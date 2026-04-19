<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

final class MethodResolver
{
    /** @var array<int, string> */
    private array $handlerPaths;
    private string $namespace;
    private string $separator;

    /**
     * @param array<int, string> $handlerPaths
     */
    public function __construct(array $handlerPaths, string $namespace, string $separator = '.')
    {
        $this->handlerPaths = $handlerPaths;
        $this->namespace = $namespace;
        $this->separator = $separator;
    }

    public function resolve(string $method): ?MethodResolution
    {
        if (!$this->isMethodSafe($method)) {
            return null;
        }

        $parts = explode($this->separator !== '' ? $this->separator : '.', $method);
        if (count($parts) < 2) {
            return null;
        }

        $handlerPart = $parts[0];
        $methodPart = $parts[1];

        // Case-insensitive file lookup for cross-platform compatibility
        $fileName = $this->findCaseInsensitiveFile($handlerPart);
        if ($fileName === null) {
            return null;
        }

        // Use the actual file basename for class resolution, consistent with HandlerRegistry
        $className = $this->namespace . basename($fileName, '.php');

        foreach ($this->handlerPaths as $path) {
            $fullPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $fileName;
            if (file_exists($fullPath)) {
                return new MethodResolution($className, $methodPart, $fullPath);
            }
        }

        return null;
    }

    /**
     * Find a PHP file with case-insensitive matching.
     * Ensures consistent behavior across case-sensitive and case-insensitive filesystems.
     */
    private function findCaseInsensitiveFile(string $handlerPart): ?string
    {
        $lowerHandler = strtolower($handlerPart);

        foreach ($this->handlerPaths as $path) {
            $realPath = realpath($path);
            if ($realPath === false || !is_dir($realPath)) {
                continue;
            }

            $files = glob($realPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
            foreach ($files as $file) {
                $baseName = basename($file, '.php');
                if (strtolower($baseName) === $lowerHandler) {
                    return $baseName . '.php';
                }
            }
        }

        return null;
    }

    private function isMethodSafe(string $method): bool
    {
        // Per JSON-RPC 2.0 spec: method names starting with 'rpc.' are reserved
        // This check is independent of the configurable method separator
        if (str_starts_with($method, 'rpc.')) {
            return false;
        }

        $sep = preg_quote($this->separator, '/');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*' . $sep . '[a-zA-Z][a-zA-Z0-9_]*$/', $method)) {
            return false;
        }

        return true;
    }
}
