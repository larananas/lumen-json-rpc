# Browser Demo

A minimal HTML page to test JSON-RPC calls directly from the browser.

## Quick Start

1. Start the basic example server:

```bash
php -S localhost:8000 -t examples/basic/public
```

2. Open `index.html` in your browser (double-click or use a local file server).

3. Set the endpoint to `http://localhost:8000/`, pick a method like `system.health`, and click **Send**.

## CORS Note

If the server and the demo page are served from different origins, the browser will block requests due to CORS. To avoid this:

- Serve `index.html` from the same origin (e.g. place it in the server's `public/` directory), or
- Add CORS headers to your server configuration.

The simplest approach is to serve the demo page through the same PHP built-in server by placing `index.html` in `examples/basic/public/`.
