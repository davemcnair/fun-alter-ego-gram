<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Queue terminal</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 24px; line-height: 1.5; }
    h1 { font-size: 22px; }
    code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
    a { color: #2563eb; }
  </style>
</head>
<body>
  <h1>Queue terminal: what's this?</h1>
  <p>Some heavy work runs in a background queue named <code>search</code>. To process it locally, run:</p>
  <pre><code>php artisan queue:work --queue=search --tries=3 --timeout=300</code></pre>
  <p>You can stop it anytime with Ctrl+C. The web UI will reflect progress as jobs are processed.</p>
  <p><a href="/">Back to app</a></p>
</body>
</html>
