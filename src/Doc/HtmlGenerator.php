<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

final class HtmlGenerator
{
    /**
     * @param array<int, MethodDoc> $docs
     */
    public function generate(array $docs, string $serverName = 'JSON-RPC 2.0 API'): string
    {
        $html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
        $html .= "<meta charset=\"UTF-8\">\n";
        $html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        $html .= "<title>" . htmlspecialchars($serverName) . "</title>\n";
        $html .= "<style>\n";
        $html .= "body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:900px;margin:0 auto;padding:20px;color:#333}\n";
        $html .= "h1{border-bottom:2px solid #4a90d9;padding-bottom:10px}\n";
        $html .= "h2{margin-top:40px;color:#2c5282}\n";
        $html .= "h3{color:#4a5568}\n";
        $html .= "code{background:#f7fafc;padding:2px 6px;border-radius:3px;font-size:0.9em}\n";
        $html .= "pre{background:#1a202c;color:#e2e8f0;padding:16px;border-radius:6px;overflow-x:auto}\n";
        $html .= "table{border-collapse:collapse;width:100%;margin:16px 0}\n";
        $html .= "th,td{border:1px solid #e2e8f0;padding:8px 12px;text-align:left}\n";
        $html .= "th{background:#edf2f7}\n";
        $html .= ".auth-required{background:#fefcbf;padding:4px 8px;border-radius:4px;font-size:0.85em}\n";
        $html .= ".toc a{color:#4a90d9;text-decoration:none}\n";
        $html .= ".toc a:hover{text-decoration:underline}\n";
        $html .= ".toc li{margin:4px 0}\n";
        $html .= "hr{border:none;border-top:1px solid #e2e8f0;margin:30px 0}\n";
        $html .= "</style>\n";
        $html .= "</head>\n<body>\n";
        $html .= "<h1>" . htmlspecialchars($serverName) . "</h1>\n";
        $html .= "<p>Auto-generated API documentation.</p>\n";

        if (!empty($docs)) {
            $html .= "<h2>Table of Contents</h2>\n<ul class=\"toc\">\n";
            foreach ($docs as $doc) {
                $anchor = strtolower(str_replace(['.', ' '], '-', $doc->name));
                $html .= "<li><a href=\"#{$anchor}\">" . htmlspecialchars($doc->name) . "</a>";
                if ($doc->requiresAuth) {
                    $html .= ' <span class="auth-required">Auth Required</span>';
                }
                $html .= "</li>\n";
            }
            $html .= "</ul>\n<hr>\n";

            foreach ($docs as $doc) {
                $html .= $this->renderMethod($doc);
            }
        }

        $html .= "</body>\n</html>";
        return $html;
    }

    private function renderMethod(MethodDoc $doc): string
    {
        $anchor = strtolower(str_replace(['.', ' '], '-', $doc->name));
        $html = "<h2 id=\"{$anchor}\"><code>" . htmlspecialchars($doc->name) . "</code></h2>\n";

        if ($doc->description) {
            $html .= "<p>" . htmlspecialchars($doc->description) . "</p>\n";
        }

        if ($doc->requiresAuth) {
            $html .= "<p><span class=\"auth-required\">Requires Authentication</span></p>\n";
        }

        if (!empty($doc->params)) {
            $html .= "<h3>Parameters</h3>\n<table><tr><th>Name</th><th>Type</th><th>Required</th><th>Description</th></tr>\n";
            foreach ($doc->params as $name => $param) {
                $required = $param['required'] ? 'Yes' : 'No';
                $html .= "<tr><td><code>" . htmlspecialchars($name) . "</code></td><td><code>" . htmlspecialchars($param['type']) . "</code></td><td>{$required}</td><td>" . htmlspecialchars($param['description']) . "</td></tr>\n";
            }
            $html .= "</table>\n";
        }

        if ($doc->returnType) {
            $html .= "<h3>Returns</h3><p><code>" . htmlspecialchars($doc->returnType) . "</code>";
            if ($doc->returnDescription) {
                $html .= " — " . htmlspecialchars($doc->returnDescription);
            }
            $html .= "</p>\n";
        }

        if (!empty($doc->errors)) {
            $html .= "<h3>Errors</h3><ul>\n";
            foreach ($doc->errors as $error) {
                $label = isset($error['code']) ? $error['code'] : ($error['type'] ?? '');
                $html .= "<li><strong>" . htmlspecialchars((string)$label) . "</strong>: " . htmlspecialchars($error['description']) . "</li>\n";
            }
            $html .= "</ul>\n";
        }

        if ($doc->exampleRequest) {
            $html .= "<h3>Example Request</h3><pre><code>" . htmlspecialchars($doc->exampleRequest) . "</code></pre>\n";
        }

        if ($doc->exampleResponse) {
            $html .= "<h3>Example Response</h3><pre><code>" . htmlspecialchars($doc->exampleResponse) . "</code></pre>\n";
        }

        $html .= "<hr>\n";
        return $html;
    }
}
