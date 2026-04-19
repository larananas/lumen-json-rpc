<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

final class JsonDocGenerator
{
    /**
     * @param array<int, MethodDoc> $docs
     */
    public function generate(array $docs, string $serverName = 'JSON-RPC 2.0 API'): string
    {
        $output = [
            'name' => $serverName,
            'version' => '2.0',
            'methods' => array_map(fn(MethodDoc $doc) => $doc->toArray(), $docs),
        ];

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
