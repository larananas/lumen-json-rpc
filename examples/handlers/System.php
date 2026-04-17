<?php

declare(strict_types=1);

namespace App\Handlers;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Support\RequestContext;

/**
 * System health and information methods.
 */
class System
{
    private ?HandlerRegistry $registry = null;

    public function __construct(
        private readonly RequestContext $context,
    ) {}

    public function setRegistry(HandlerRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Check server health status.
     *
     * @return array status
     * @example-request {"jsonrpc": "2.0", "method": "system.health", "id": 1}
     * @example-response {"status": "ok", "timestamp": "2025-01-01T00:00:00Z"}
     */
    public function health(): array
    {
        return [
            'status' => 'ok',
            'timestamp' => date('c'),
        ];
    }

    /**
     * Get server version information.
     *
     * @return array version info
     * @example-request {"jsonrpc": "2.0", "method": "system.version", "id": 1}
     * @example-response {"version": "1.0.0", "protocol": "2.0"}
     */
    public function version(): array
    {
        $version = $this->context->getAttribute('serverVersion', '1.0.0');
        return [
            'version' => $version,
            'protocol' => '2.0',
        ];
    }

    /**
     * List all available JSON-RPC methods.
     *
     * @return string[] method names
     * @example-request {"jsonrpc": "2.0", "method": "system.methods", "id": 1}
     * @example-response ["system.health", "system.version", "system.methods"]
     */
    public function methods(): array
    {
        if ($this->registry !== null) {
            return $this->registry->getMethodNames();
        }

        return [
            'system.health',
            'system.version',
            'system.methods',
        ];
    }
}
