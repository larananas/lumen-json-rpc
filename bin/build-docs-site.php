<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$outputDir = $root . DIRECTORY_SEPARATOR . 'docs-site';
$srcDir = $root . DIRECTORY_SEPARATOR . 'docs-site-src';
$docsDir = $root . DIRECTORY_SEPARATOR . 'docs';

if (is_dir($outputDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outputDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($outputDir);
}

mkdir($outputDir, 0755, true);

$siteConfig = [
    'name' => 'Lumen JSON-RPC',
    'tagline' => 'Framework-free JSON-RPC for PHP',
    'version' => '1.0.0',
    'logo_svg' => file_exists($root . '/.github/assets/logo-squared.svg')
        ? '.github/assets/logo-squared.svg'
        : null,
    'nav' => [
        ['title' => 'Home', 'href' => 'index.html'],
        ['title' => 'Installation', 'href' => 'installation.html'],
        ['title' => 'Quick Start', 'href' => 'quickstart.html'],
        ['title' => 'Configuration', 'href' => 'configuration.html'],
        ['title' => 'Authentication', 'href' => 'authentication.html'],
        ['title' => 'Handlers', 'href' => 'handlers.html'],
        ['title' => 'Requests & Responses', 'href' => 'requests.html'],
        ['title' => 'Middleware & Hooks', 'href' => 'middleware.html'],
        ['title' => 'Schema Validation', 'href' => 'schema-validation.html'],
        ['title' => 'Rate Limiting & Security', 'href' => 'security.html'],
        ['title' => 'Docs Generation', 'href' => 'docs-generation.html'],
        ['title' => 'OpenRPC', 'href' => 'openrpc.html'],
        ['title' => 'Examples', 'href' => 'examples.html'],
        ['title' => 'Architecture', 'href' => 'architecture.html'],
        ['title' => 'Quality & Release', 'href' => 'quality.html'],
    ],
];

$pages = [];

$pages['index'] = buildPage($siteConfig, 'Home', 'index', renderHomePage($siteConfig));
$pages['installation'] = buildPage($siteConfig, 'Installation', 'installation', renderInstallationPage());

$pages['quickstart'] = buildPage($siteConfig, 'Quick Start', 'quickstart', renderQuickStartPage());
$pages['configuration'] = buildPage($siteConfig, 'Configuration', 'configuration', renderFileContent($docsDir . '/configuration.md'));
$pages['authentication'] = buildPage($siteConfig, 'Authentication', 'authentication', renderFileContent($docsDir . '/authentication.md'));
$pages['handlers'] = buildPage($siteConfig, 'Handlers', 'handlers', renderHandlersPage());
$pages['requests'] = buildPage($siteConfig, 'Requests & Responses', 'requests', renderRequestsPage());
$pages['middleware'] = buildPage($siteConfig, 'Middleware & Hooks', 'middleware', renderMiddlewarePage());
$pages['schema-validation'] = buildPage($siteConfig, 'Schema Validation', 'schema-validation', renderSchemaValidationPage());
$pages['security'] = buildPage($siteConfig, 'Rate Limiting & Security', 'security', renderFileContent($docsDir . '/security.md'));
$pages['docs-generation'] = buildPage($siteConfig, 'Docs Generation', 'docs-generation', renderDocsGenerationPage());
$pages['openrpc'] = buildPage($siteConfig, 'OpenRPC', 'openrpc', renderOpenRpcPage());
$pages['examples'] = buildPage($siteConfig, 'Examples', 'examples', renderExamplesPage());
$pages['architecture'] = buildPage($siteConfig, 'Architecture', 'architecture', renderFileContent($docsDir . '/architecture.md'));
$pages['quality'] = buildPage($siteConfig, 'Quality & Release', 'quality', renderQualityPage());

foreach ($pages as $name => $html) {
    file_put_contents($outputDir . '/' . $name . '.html', $html);
}

$cname = getenv('DOCS_CNAME');
if ($cname !== false && $cname !== '') {
    file_put_contents($outputDir . '/CNAME', $cname);
    echo "CNAME: {$cname}\n";
}

file_put_contents($outputDir . '/.nojekyll', '');

file_put_contents($outputDir . '/404.html', buildPage($siteConfig, 'Not Found', '', <<<HTML
<h1>Page not found</h1>
<p>The page you are looking for does not exist.</p>
<p><a href="index.html">Back to home</a></p>
HTML));

$copyLogo = $root . '/.github/assets/logo-squared.png';
if (file_exists($copyLogo)) {
    copy($copyLogo, $outputDir . '/logo.png');
}
$copyLogoSvg = $root . '/.github/assets/logo-squared.svg';
if (file_exists($copyLogoSvg)) {
    copy($copyLogoSvg, $outputDir . '/logo.svg');
}

$count = count($pages);
echo "Docs site built: {$count} pages -> docs-site/\n";

/**
 * @param array{name: string, tagline: string, version: string, nav: array<int, array{title: string, href: string}>} $config
 */
function buildPage(array $config, string $title, string $activeSlug, string $bodyHtml): string
{
    $navHtml = '';
    foreach ($config['nav'] as $item) {
        $active = ($item['href'] === $activeSlug . '.html') ? ' active' : '';
        $navHtml .= '<a class="nav-link' . $active . '" href="' . $item['href'] . '">' . htmlspecialchars($item['title']) . "</a>\n";
    }

    $siteName = htmlspecialchars($config['name']);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$siteName} — {$title}</title>
<link rel="icon" href="logo.svg" type="image/svg+xml">
<style>
:root{--bg:#fff;--surface:#f8f9fa;--border:#e2e8f0;--text:#1a202c;--text2:#4a5568;--accent:#3182ce;--accent2:#2c5282;--code-bg:#1a202c;--code-fg:#e2e8f0;--success:#38a169;--warning:#d69e2e;--radius:6px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--text);line-height:1.6;background:var(--bg)}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
code{font-family:"SF Mono","Fira Code",Consolas,monospace;font-size:.9em;background:var(--surface);padding:2px 6px;border-radius:3px}
pre{background:var(--code-bg);color:var(--code-fg);padding:16px;border-radius:var(--radius);overflow-x:auto;margin:12px 0}
pre code{background:none;padding:0;color:inherit}
h1,h2,h3,h4{color:var(--accent2);margin:24px 0 12px}
h1{font-size:1.8em;border-bottom:2px solid var(--border);padding-bottom:8px}
h2{font-size:1.4em}
h3{font-size:1.15em}
table{border-collapse:collapse;width:100%;margin:12px 0}
th,td{border:1px solid var(--border);padding:8px 12px;text-align:left}
th{background:var(--surface)}
blockquote{border-left:4px solid var(--accent);margin:12px 0;padding:8px 16px;background:var(--surface);border-radius:0 var(--radius) var(--radius) 0}
hr{border:none;border-top:1px solid var(--border);margin:24px 0}
.layout{display:flex;min-height:100vh}
.sidebar{width:240px;background:var(--surface);border-right:1px solid var(--border);padding:20px 0;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.sidebar-header{padding:0 20px 20px;border-bottom:1px solid var(--border);margin-bottom:8px}
.sidebar-header h2{font-size:1.1em;margin:0;color:var(--accent2)}
.sidebar-header p{font-size:.8em;color:var(--text2);margin:4px 0 0}
.nav-link{display:block;padding:8px 20px;color:var(--text2);font-size:.9em;transition:all .15s}
.nav-link:hover{background:var(--border);text-decoration:none;color:var(--text)}
.nav-link.active{color:var(--accent);background:rgba(49,130,206,.08);font-weight:600;border-right:3px solid var(--accent)}
.main{flex:1;padding:32px 48px;max-width:900px}
.main p{margin:8px 0}
.main ul,.main ol{margin:8px 0 8px 24px}
.main li{margin:4px 0}
.badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:.8em;font-weight:600}
.badge-green{background:#c6f6d5;color:#22543d}
.badge-blue{background:#bee3f8;color:#2a4365}
.badge-yellow{background:#fefcbf;color:#744210}
.hero{text-align:center;padding:48px 0 32px}
.hero h1{font-size:2.2em;border:none}
.hero p{font-size:1.15em;color:var(--text2)}
.hero .tagline{margin:12px 0 24px}
.cta{display:inline-block;background:var(--accent);color:#fff;padding:10px 24px;border-radius:var(--radius);font-weight:600;margin:4px}
.cta:hover{background:var(--accent2);text-decoration:none;color:#fff}
.cta-secondary{background:var(--surface);color:var(--accent);border:1px solid var(--border)}
.cta-secondary:hover{background:var(--border)}
.features{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:24px 0}
.feature{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px}
.feature h3{margin:0 0 4px;font-size:1em}
.feature p{font-size:.9em;color:var(--text2);margin:0}
@media(max-width:768px){.layout{flex-direction:column}.sidebar{width:100%;height:auto;position:relative;border-right:none;border-bottom:1px solid var(--border)}.main{padding:16px}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
<div class="sidebar-header">
<h2>{$siteName}</h2>
<p>Framework-free JSON-RPC</p>
</div>
<nav>
{$navHtml}
</nav>
</aside>
<main class="main">
{$bodyHtml}
<footer style="margin-top:48px;padding:16px 0;border-top:1px solid var(--border);font-size:.85em;color:var(--text2)">
<a href="https://github.com/larananas/lumen-json-rpc" style="color:var(--text2)">GitHub</a>
</footer>
</main>
</div>
</body>
</html>
HTML;
}

/**
 * @param array{name: string, tagline: string} $config
 */
function renderHomePage(array $config): string
{
    $name = htmlspecialchars($config['name']);
    $tagline = htmlspecialchars($config['tagline']);

    return <<<HTML
<div class="hero">
<h1>{$name}</h1>
<p class="tagline">{$tagline}</p>
<p>Strict handler.method routing, strong defaults, auth drivers, gzip, rate limiting, middleware, schema validation, and docs generation.</p>
<div style="margin-top:20px">
<a class="cta" href="quickstart.html">Quick Start</a>
<a class="cta cta-secondary" href="installation.html">Installation</a>
</div>
</div>
<div class="features">
<div class="feature"><h3>Framework-free</h3><p>Plain PHP. No framework required. No heavy dependencies.</p></div>
<div class="feature"><h3>handler.method routing</h3><p>Predictable, explicit, reviewable method dispatch.</p></div>
<div class="feature"><h3>Auth drivers</h3><p>JWT, API key, HTTP Basic built in.</p></div>
<div class="feature"><h3>Schema validation</h3><p>Optional JSON Schema subset validation on handler params.</p></div>
<div class="feature"><h3>Rate limiting</h3><p>Pluggable backends, file-based default, atomic batch weight.</p></div>
<div class="feature"><h3>Docs generation</h3><p>Markdown, HTML, JSON, OpenRPC from handler metadata.</p></div>
<div class="feature"><h3>Middleware pipeline</h3><p>Wrap request execution with before/after logic.</p></div>
<div class="feature"><h3>Production ready</h3><p>Strict validation, safe defaults, comprehensive tests.</p></div>
</div>
HTML;
}

function renderInstallationPage(): string
{
    return <<<'HTML'
<h1>Installation</h1>
<h2>Requirements</h2>
<ul>
<li><strong>PHP 8.1+</strong></li>
<li><strong>ext-json</strong></li>
</ul>
<h2>Install via Composer</h2>
<pre><code>composer require larananas/lumen-json-rpc</code></pre>
<h2>Optional extras</h2>
<table>
<tr><th>Extra</th><th>Purpose</th></tr>
<tr><td><code>ext-zlib</code></td><td>Enables gzip request/response compression support</td></tr>
<tr><td><code>firebase/php-jwt</code></td><td>Broader JWT algorithm support beyond built-in HMAC</td></tr>
</table>
<p>Without optional extras, the library works normally.</p>
<h2>Verify installation</h2>
<pre><code>php -r "echo class_exists('Lumen\\JsonRpc\\Server\\JsonRpcServer') ? 'OK' : 'FAIL';"</code></pre>
HTML;
}

function renderQuickStartPage(): string
{
    return <<<HTML
<h1>Quick Start</h1>
<h2>1) Create an entry point</h2>
<p>Create <code>public/index.php</code>:</p>
<pre><code>&lt;?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;

\$config = new Config([
    'handlers' => [
        'paths'    => [__DIR__ . '/../handlers'],
        'namespace' => 'App\\Handlers\\',
    ],
]);

\$server = new JsonRpcServer(\$config);
\$server-&gt;run();</code></pre>

<h2>2) Create a handler</h2>
<p>Create <code>handlers/User.php</code>:</p>
<pre><code>&lt;?php
declare(strict_types=1);
namespace App\Handlers;

use Lumen\JsonRpc\Support\RequestContext;

final class User
{
    public function get(RequestContext \$context, int \$id): array
    {
        return [
            'id'   => \$id,
            'name' => 'Example User',
        ];
    }
}</code></pre>

<h2>3) Send a request</h2>
<pre><code>curl -X POST http://localhost:8000/ \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1}'</code></pre>

<h2>4) Response</h2>
<pre><code>{"jsonrpc":"2.0","result":{"id":1,"name":"Example User"},"id":1}</code></pre>

<h2>The mental model</h2>
<table>
<tr><th>JSON-RPC Method</th><th>Handler Class</th><th>Method</th></tr>
<tr><td><code>user.get</code></td><td><code>handlers/User.php</code></td><td><code>get()</code></td></tr>
<tr><td><code>user.create</code></td><td><code>handlers/User.php</code></td><td><code>create()</code></td></tr>
<tr><td><code>system.health</code></td><td><code>handlers/System.php</code></td><td><code>health()</code></td></tr>
</table>

<p>No manual method registry. No hidden auto-generated procedures. Just <code>handler.method</code>.</p>
HTML;
}

function renderHandlersPage(): string
{
    return <<<HTML
<h1>Handlers & Method Naming</h1>
<h2>handler.method pattern</h2>
<p>Methods follow the <code>handler.method</code> pattern. When you see <code>user.get</code>:</p>
<ul>
<li>Handler class <code>User</code></li>
<li>Method <code>get()</code></li>
<li>In your configured handlers path</li>
</ul>

<h2>Handler safety rules</h2>
<ul>
<li>Methods starting with <code>rpc.</code> are always rejected</li>
<li>Method names must match <code>handler.method</code></li>
<li>Magic methods (<code>__construct</code>, <code>__call</code>, etc.) are blocked</li>
<li>Only <strong>public instance methods</strong> on the concrete handler class are callable</li>
<li>Static methods are excluded</li>
<li>Inherited methods are excluded</li>
</ul>

<h2>Parameter binding</h2>
<p>Parameters are type-checked and mapped to <code>-32602 Invalid params</code> for mismatches.</p>
<ul>
<li>Wrong scalar types produce <code>-32602</code></li>
<li>Missing required parameters produce <code>-32602</code></li>
<li>Unknown named parameters produce <code>-32602</code></li>
<li>Surplus positional parameters produce <code>-32602</code></li>
<li>Optional parameters use their defaults when omitted</li>
<li>Both positional and named parameters are supported</li>
<li><code>int</code> to <code>float</code> coercion is allowed</li>
<li><code>RequestContext</code> is injected automatically when declared as the first method parameter</li>
</ul>

<h2>Explicit procedure descriptors</h2>
<p>For advanced use cases, you can register procedures explicitly alongside auto-discovery:</p>
<pre><code>use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;

\$registry = \$server-&gt;getRegistry();
\$registry-&gt;register('math.add', MathHandler::class, 'add', [
    'description' => 'Add two numbers',
]);</code></pre>

<h2>Handler factory (DI)</h2>
<p>Inject app services into handlers without forcing a framework container:</p>
<pre><code>use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;

\$factory = new class(\$db) implements HandlerFactoryInterface {
    public function __construct(private DatabaseService \$db) {}

    public function create(string \$className, RequestContext \$context): object
    {
        return new \$className(\$this-&gt;db);
    }
};
\$server-&gt;setHandlerFactory(\$factory);</code></pre>
HTML;
}

function renderRequestsPage(): string
{
    return <<<HTML
<h1>Requests, Responses & Errors</h1>
<h2>Request format</h2>
<pre><code>{"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1}</code></pre>

<h2>Batch requests</h2>
<pre><code>[
  {"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1},
  {"jsonrpc":"2.0","method":"system.health","id":2}
]</code></pre>
<p>Batch limits: configurable <code>batch.max_items</code> (default: 100). Empty batch returns <code>-32600</code>.</p>

<h2>Notifications</h2>
<p>Requests without an <code>id</code> field are notifications. The server processes them but returns no response.</p>
<p>Batch of only notifications returns HTTP 204.</p>

<h2>Error codes</h2>
<table>
<tr><th>Code</th><th>Meaning</th><th>When</th></tr>
<tr><td>-32700</td><td>Parse error</td><td>Invalid JSON</td></tr>
<tr><td>-32600</td><td>Invalid Request</td><td>Malformed request, empty body, empty batch</td></tr>
<tr><td>-32601</td><td>Method not found</td><td>Unknown or reserved method</td></tr>
<tr><td>-32602</td><td>Invalid params</td><td>Missing, wrong type, unknown, or surplus</td></tr>
<tr><td>-32603</td><td>Internal error</td><td>Handler/middleware exception</td></tr>
<tr><td>-32000</td><td>Rate limit exceeded</td><td>Too many requests</td></tr>
<tr><td>-32001</td><td>Auth required</td><td>Protected method without credentials</td></tr>
<tr><td>-32099</td><td>Custom server error</td><td>Application-defined</td></tr>
</table>

<h2>Transport behavior</h2>
<ul>
<li><code>POST /</code> handles JSON-RPC requests</li>
<li>Empty POST body returns <code>-32600 Invalid Request</code></li>
<li><code>GET /</code> returns health JSON when enabled</li>
<li>Non-POST, non-GET methods return HTTP 405</li>
</ul>
HTML;
}

function renderMiddlewarePage(): string
{
    return <<<HTML
<h1>Middleware & Hooks</h1>
<h2>Middleware pipeline</h2>
<p>Run logic before/after each request without mixing it into handlers:</p>
<pre><code>use Lumen\JsonRpc\Middleware\MiddlewareInterface;

\$server-&gt;addMiddleware(new class implements MiddlewareInterface {
    public function process(Request \$request, RequestContext \$context, callable \$next): ?Response
    {
        error_log("[JSON-RPC] -&gt; {\$request-&gt;method}");
        \$response = \$next(\$request, \$context);
        error_log('[JSON-RPC] &lt;- done');
        return \$response;
    }
});</code></pre>

<h2>Hook system</h2>
<p>Hooks fire at defined lifecycle points:</p>
<pre><code>BEFORE_REQUEST -&gt; BEFORE_HANDLER -&gt; [handler] -&gt; AFTER_HANDLER -&gt; ON_RESPONSE -&gt; AFTER_REQUEST
ON_ERROR fires instead of AFTER_HANDLER on exception.
ON_AUTH_SUCCESS / ON_AUTH_FAILURE fire during authentication.</code></pre>

<h2>Hook example</h2>
<pre><code>\$server-&gt;getHooks()-&gt;register(
    HookPoint::BEFORE_HANDLER,
    function (array \$context) {
        return ['custom_data' => 'value'];
    }
);</code></pre>

<h2>When to use which</h2>
<ul>
<li><strong>Hooks</strong> — lightweight lifecycle events, observability</li>
<li><strong>Middleware</strong> — wrapping request execution, short-circuiting, auth overrides</li>
</ul>
HTML;
}

function renderSchemaValidationPage(): string
{
    return <<<'HTML'
<h1>Schema Validation</h1>
<h2>Overview</h2>
<p>The library includes a built-in JSON Schema subset validator for validating handler parameters. It is optional — normal parameter binding works without it.</p>

<h2>Enabling schema validation</h2>
<pre><code>'validation' => [
    'strict' => true,
    'schema' => ['enabled' => true],
],</code></pre>

<h2>Defining schemas on handlers</h2>
<p>Implement <code>RpcSchemaProviderInterface</code>:</p>
<pre><code>use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;

final class Product implements RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'create' => [
                'type'       => 'object',
                'required'   => ['name', 'price'],
                'properties' => [
                    'name'  => ['type' => 'string', 'minLength' => 1],
                    'price' => ['type' => 'number'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }
}</code></pre>

<h2>Supported JSON Schema keywords</h2>
<table>
<tr><th>Keyword</th><th>Status</th><th>Notes</th></tr>
<tr><td><code>type</code></td><td><span class="badge badge-green">Supported</span></td><td>string, integer/int, number, boolean/bool, array, object, null</td></tr>
<tr><td><code>required</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>properties</code></td><td><span class="badge badge-green">Supported</span></td><td>Nested validation</td></tr>
<tr><td><code>additionalProperties</code></td><td><span class="badge badge-green">Supported</span></td><td>Boolean or schema</td></tr>
<tr><td><code>items</code></td><td><span class="badge badge-green">Supported</span></td><td>Array item validation</td></tr>
<tr><td><code>minItems</code> / <code>maxItems</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>uniqueItems</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>minLength</code> / <code>maxLength</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>pattern</code></td><td><span class="badge badge-green">Supported</span></td><td>Regex patterns</td></tr>
<tr><td><code>minimum</code> / <code>maximum</code></td><td><span class="badge badge-green">Supported</span></td><td>Inclusive bounds</td></tr>
<tr><td><code>exclusiveMinimum</code> / <code>exclusiveMaximum</code></td><td><span class="badge badge-green">Supported</span></td><td>Exclusive bounds</td></tr>
<tr><td><code>enum</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>const</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>allOf</code> / <code>anyOf</code> / <code>oneOf</code></td><td><span class="badge badge-green">Supported</span></td><td>Composition</td></tr>
<tr><td><code>not</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>minProperties</code> / <code>maxProperties</code></td><td><span class="badge badge-green">Supported</span></td><td></td></tr>
<tr><td><code>format</code></td><td><span class="badge badge-blue">Partial</span></td><td>See supported formats below</td></tr>
</table>

<h2>Supported <code>format</code> values</h2>
<table>
<tr><th>Format</th><th>Description</th></tr>
<tr><td><code>email</code></td><td>Email address (RFC 5322 subset)</td></tr>
<tr><td><code>uri</code></td><td>URI with scheme</td></tr>
<tr><td><code>url</code></td><td>HTTP/HTTPS URL</td></tr>
<tr><td><code>uuid</code></td><td>UUID (any version, case-insensitive)</td></tr>
<tr><td><code>ipv4</code></td><td>IPv4 address</td></tr>
<tr><td><code>ipv6</code></td><td>IPv6 address</td></tr>
<tr><td><code>date-time</code></td><td>ISO 8601 date-time (requires timezone)</td></tr>
<tr><td><code>date</code></td><td>ISO 8601 date (YYYY-MM-DD)</td></tr>
<tr><td><code>time</code></td><td>ISO 8601 time (HH:MM:SS)</td></tr>
</table>
<p>Unknown format values are silently ignored (not validated).</p>

<h2>Intentionally unsupported</h2>
<ul>
<li><code>$ref</code> — schema references</li>
<li><code>if/then/else</code> — conditional schemas</li>
<li><code>dependencies</code> / <code>dependentSchemas</code></li>
<li><code>prefixItems</code> / <code>tuple validation</code></li>
<li><code>contentEncoding</code> / <code>contentMediaType</code></li>
<li>Numeric <code>multipleOf</code></li>
<li><code>patternProperties</code></li>
<li><code>format</code> values beyond the list above</li>
</ul>
<p>These are omitted to keep the validator simple, deterministic, and suitable for runtime parameter validation without external dependencies.</p>

<h2>Max depth protection</h2>
<p>Nested schema validation is capped at 32 levels to prevent stack overflow from deeply nested schemas.</p>
HTML;
}

function renderDocsGenerationPage(): string
{
    return <<<'HTML'
<h1>Documentation Generation</h1>
<h2>Generate docs from handlers</h2>
<pre><code>php bin/generate-docs.php --format=markdown --output=docs/api.md
php bin/generate-docs.php --format=html --output=docs/api.html
php bin/generate-docs.php --format=json --output=docs/api.json
php bin/generate-docs.php --format=openrpc --output=docs/openrpc.json</code></pre>

<h2>Supported formats</h2>
<table>
<tr><th>Format</th><th>Description</th></tr>
<tr><td><code>markdown</code></td><td>Markdown documentation (default)</td></tr>
<tr><td><code>html</code></td><td>Styled HTML page</td></tr>
<tr><td><code>json</code></td><td>Machine-readable JSON</td></tr>
<tr><td><code>openrpc</code></td><td>OpenRPC 1.3.2 specification</td></tr>
</table>

<h2>Doc metadata sources</h2>
<p>Documentation is generated from:</p>
<ul>
<li>Handler method reflection (types, defaults, required)</li>
<li>PHPDoc tags (<code>@param</code>, <code>@return</code>, <code>@throws</code>, <code>@error</code>)</li>
<li>Procedure descriptor metadata</li>
<li><code>@requiresAuth</code> / <code>@authenticated</code> annotations</li>
<li><code>@example-request</code> / <code>@example-response</code> annotations</li>
</ul>
HTML;
}

function renderOpenRpcPage(): string
{
    return <<<'HTML'
<h1>OpenRPC Support</h1>
<h2>Generating OpenRPC specs</h2>
<pre><code>php bin/generate-docs.php --format=openrpc --output=docs/openrpc.json</code></pre>
<p>Produces an <a href="https://spec.open-rpc.org/">OpenRPC 1.3.2</a> compliant specification from your handlers.</p>

<h2>Validation approach</h2>
<p>The OpenRPC output is validated using:</p>
<ul>
<li><strong>Structural validation</strong>: PHP tests verify required fields, correct types, and valid structure against the OpenRPC 1.3.2 schema definitions</li>
<li><strong>Pinned schema fixture</strong>: The official OpenRPC 1.3.2 JSON Schema is bundled in <code>tests/Fixtures/openrpc-schema-1.3.2.json</code></li>
<li><strong>Property coverage tests</strong>: Verify required fields on method objects, content descriptors, error objects, and tag objects</li>
</ul>

<h2>Honest scope</h2>
<p>The validation is <strong>structural, not formal</strong>. It checks:</p>
<ul>
<li>Top-level required fields (<code>openrpc</code>, <code>info</code>, <code>methods</code>)</li>
<li>Info block structure</li>
<li>Server entries</li>
<li>Method object required fields (<code>name</code>, <code>params</code>, <code>result</code>)</li>
<li>Content descriptor required fields (<code>name</code>, <code>schema</code>)</li>
<li>Error object required fields (<code>code</code>, <code>message</code>)</li>
<li>Extension fields (<code>x-requiresAuth</code>)</li>
</ul>
<p>It does <strong>not</strong> validate against the full JSON Schema meta-schema using a machine validator. Full formal validation against the official JSON Schema would require a complete JSON Schema validator library, which this project intentionally avoids as a runtime dependency.</p>

<h2>PHP type to JSON Schema mapping</h2>
<table>
<tr><th>PHP Type</th><th>JSON Schema</th></tr>
<tr><td><code>int</code></td><td><code>{"type":"integer"}</code></td></tr>
<tr><td><code>float</code></td><td><code>{"type":"number"}</code></td></tr>
<tr><td><code>bool</code></td><td><code>{"type":"boolean"}</code></td></tr>
<tr><td><code>string</code></td><td><code>{"type":"string"}</code></td></tr>
<tr><td><code>array</code></td><td><code>{"type":"array"}</code></td></tr>
<tr><td><code>array&lt;T&gt;</code></td><td><code>{"type":"array","items":...}</code></td></tr>
<tr><td><code>object</code></td><td><code>{"type":"object"}</code></td></tr>
<tr><td><code>?T</code></td><td><code>{"oneOf":[{"type":"T"},{"type":"null"}]}</code></td></tr>
<tr><td><code>mixed</code></td><td><code>{"description":"Any value"}</code></td></tr>
<tr><td><code>void</code></td><td><code>{"type":"null"}</code></td></tr>
</table>
HTML;
}

function renderExamplesPage(): string
{
    return <<<'HTML'
<h1>Examples</h1>
<h2>Basic example</h2>
<p>A minimal server with handlers and no auth. See <code>examples/basic/</code>.</p>

<h2>Auth example</h2>
<p>JWT auth with a working example app. See <code>examples/auth/</code>.</p>

<h2>Advanced example</h2>
<p>Custom handler factory, middleware, schema validation. See <code>examples/advanced/</code>.</p>

<h2>Browser demo</h2>
<p>A tiny HTML page for sending raw JSON-RPC requests. See <code>examples/browser-demo/</code>.</p>

<h2>Usage patterns</h2>
<h3>Direct core usage (no HTTP)</h3>
<pre><code>$json = $server->handleJson(
    '{"jsonrpc":"2.0","method":"system.health","id":1}',
    $context,
);</code></pre>

<h3>Custom rate limiter backend</h3>
<pre><code>$server->getEngine()->getRateLimitManager()->setLimiter(new MyRedisRateLimiter());</code></pre>

<h3>Response fingerprinting</h3>
<pre><code>'response_fingerprint' => ['enabled' => true, 'algorithm' => 'sha256'],</code></pre>
HTML;
}

function renderQualityPage(): string
{
    return <<<'HTML'
<h1>Quality & Release</h1>
<h2>QA commands</h2>
<table>
<tr><th>Command</th><th>Purpose</th></tr>
<tr><td><code>composer qa</code></td><td>Standard quality gate: validate, audit, package verify, lint, stan, test</td></tr>
<tr><td><code>composer qa:max</code></td><td>Maximum quality bar: all of qa + coverage + mutation testing</td></tr>
<tr><td><code>composer test</code></td><td>Run PHPUnit tests</td></tr>
<tr><td><code>composer test:coverage</code></td><td>Run tests with coverage report</td></tr>
<tr><td><code>composer stan</code></td><td>PHPStan static analysis</td></tr>
<tr><td><code>composer lint</code></td><td>PHP syntax lint (src + bin + examples)</td></tr>
<tr><td><code>composer mutate</code></td><td>Infection mutation testing</td></tr>
<tr><td><code>composer verify:package</code></td><td>Verify package archive is clean</td></tr>
</table>

<h2>CI pipeline</h2>
<p>GitHub Actions CI runs on every push and PR:</p>
<ul>
<li><strong>Quality</strong> (PHP 8.3): composer validate, audit, package verify, syntax lint, PHPStan</li>
<li><strong>Tests</strong> (PHP 8.1, 8.2, 8.3, 8.4): PHPUnit</li>
<li><strong>Coverage</strong> (PHP 8.3): Coverage report + threshold check</li>
<li><strong>Mutation</strong> (PHP 8.3): Infection mutation testing (80% MSI, 85% covered MSI)</li>
</ul>

<h2>qa:max vs CI equivalence</h2>
<p><code>composer qa:max</code> runs locally: validate, audit, package verify, syntax lint, PHPStan, coverage with threshold, mutation testing.</p>
<p>CI runs the same checks split across parallel jobs. The CI quality job includes lint+stan (matching the quality half of qa:max). Coverage and mutation run in separate CI jobs. The commands are identical.</p>

<h2>Design principles</h2>
<ul>
<li>Framework-free, explicit, reviewable</li>
<li>Safe defaults, no magic</li>
<li>Honest claims only</li>
<li>No feature theater</li>
</ul>
HTML;
}

function renderFileContent(string $filePath, ?string $fallbackContent = null): string
{
    if (!file_exists($filePath)) {
        return $fallbackContent ?? '<p>Documentation not available.</p>';
    }

    $md = file_get_contents($filePath);
    if ($md === false) {
        return $fallbackContent ?? '<p>Documentation not available.</p>';
    }

    $md = ltrim($md);
    $html = mdToHtml($md);

    if (($html === '' || trim($html) === '') && $fallbackContent !== null) {
        return $fallbackContent;
    }

    return $html;
}

function mdToHtml(string $md): string
{
    $lines = explode("\n", $md);
    $html = '';
    $inCodeBlock = false;
    $inTable = false;
    $tableRows = [];

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '```')) {
            if ($inCodeBlock) {
                $html .= '</code></pre>';
                $inCodeBlock = false;
            } else {
                $html .= '<pre><code>';
                $inCodeBlock = true;
            }
            continue;
        }

        if ($inCodeBlock) {
            $html .= htmlspecialchars($line) . "\n";
            continue;
        }

        $trimmed = trim($line);

        if ($trimmed === '---' || $trimmed === '***' || $trimmed === '___') {
            $html .= flushTable($inTable, $tableRows);
            $inTable = false;
            $html .= '<hr>';
            continue;
        }

        if (preg_match('/^\|(.+)\|$/', $trimmed, $m)) {
            if (!$inTable) {
                $inTable = true;
            }
            $cells = array_map('trim', explode('|', trim($m[1], '|')));
            $tableRows[] = $cells;
            continue;
        } else {
            if ($inTable) {
                $html .= flushTable($inTable, $tableRows);
                $inTable = false;
            }
        }

        if (preg_match('/^#{1,4}\s+(.+)$/', $trimmed, $m)) {
            $level = min(4, strlen(explode(' ', $trimmed)[0]));
            $headingText = inlineMarkdown(trim($m[1]));
            $html .= "<h{$level}>{$headingText}</h{$level}>\n";
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            $html .= '<li>' . inlineMarkdown($m[1]) . "</li>\n";
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $html .= '<li>' . inlineMarkdown($m[1]) . "</li>\n";
            continue;
        }

        if (str_starts_with($trimmed, '> ')) {
            $quoteText = inlineMarkdown(substr($trimmed, 2));
            $html .= "<blockquote>{$quoteText}</blockquote>\n";
            continue;
        }

        if ($trimmed !== '') {
            $html .= '<p>' . inlineMarkdown($trimmed) . "</p>\n";
        }
    }

    if ($inCodeBlock) {
        $html .= '</code></pre>';
    }
    if ($inTable) {
        $html .= flushTable($inTable, $tableRows);
    }

    return $html;
}

/**
 * @param array<int, array<int, string>> $rows
 */
function flushTable(bool &$inTable, array &$rows): string
{
    if (empty($rows)) {
        $inTable = false;
        return '';
    }

    $html = '<table>';
    $isFirst = true;
    foreach ($rows as $i => $cells) {
        if ($isFirst && count($rows) > 1) {
            $isFirst = false;
            if (preg_match('/^[\s|:-]+$/', implode('', $cells))) {
                continue;
            }
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<th>' . inlineMarkdown($cell) . '</th>';
            }
            $html .= '</tr>';
            continue;
        }
        if ($i === 1 && count($rows) > 2 && preg_match('/^[\s|:-]+$/', implode('', $cells))) {
            continue;
        }
        $html .= '<tr>';
        foreach ($cells as $cell) {
            $html .= '<td>' . inlineMarkdown($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    $rows = [];
    $inTable = false;
    return $html;
}

function inlineMarkdown(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
    $text = (string) preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
    return $text;
}
