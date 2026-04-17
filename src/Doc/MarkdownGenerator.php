<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

final class MarkdownGenerator
{
    public function generate(array $docs, string $serverName = 'JSON-RPC 2.0 API'): string
    {
        $md = "# $serverName\n\n";
        $md .= "Auto-generated API documentation.\n\n";
        $md .= "---\n\n";

        if (empty($docs)) {
            $md .= "No methods documented.\n";
            return $md;
        }

        $md .= "## Table of Contents\n\n";
        foreach ($docs as $doc) {
            $anchor = strtolower(str_replace(['.', ' '], '-', $doc->name));
            $md .= "- [{$doc->name}](#$anchor)";
            if ($doc->requiresAuth) {
                $md .= ' :closed_lock_with_key:';
            }
            $md .= "\n";
        }
        $md .= "\n---\n\n";

        foreach ($docs as $doc) {
            $md .= $this->renderMethod($doc);
        }

        return $md;
    }

    private function renderMethod(MethodDoc $doc): string
    {
        $md = "## `{$doc->name}`\n\n";

        if ($doc->description) {
            $md .= "{$doc->description}\n\n";
        }

        if ($doc->requiresAuth) {
            $md .= "> **Requires Authentication**\n\n";
        }

        if (!empty($doc->params)) {
            $md .= "### Parameters\n\n";
            $md .= "| Name | Type | Required | Description |\n";
            $md .= "|------|------|----------|-------------|\n";
            foreach ($doc->params as $name => $param) {
                $required = $param['required'] ? 'Yes' : 'No';
                $md .= "| `$name` | `{$param['type']}` | $required | {$param['description']} |\n";
            }
            $md .= "\n";
        }

        if ($doc->returnType) {
            $md .= "### Returns\n\n";
            $md .= "`{$doc->returnType}`";
            if ($doc->returnDescription) {
                $md .= " — {$doc->returnDescription}";
            }
            $md .= "\n\n";
        }

        if (!empty($doc->errors)) {
            $md .= "### Errors\n\n";
            foreach ($doc->errors as $error) {
                if (isset($error['code'])) {
                    $md .= "- **{$error['code']}**: {$error['description']}\n";
                } else {
                    $md .= "- **{$error['type']}**: {$error['description']}\n";
                }
            }
            $md .= "\n";
        }

        if ($doc->exampleRequest) {
            $md .= "### Example Request\n\n```json\n{$doc->exampleRequest}\n```\n\n";
        }

        if ($doc->exampleResponse) {
            $md .= "### Example Response\n\n```json\n{$doc->exampleResponse}\n```\n\n";
        }

        $md .= "---\n\n";
        return $md;
    }
}
