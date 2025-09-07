<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fun Alter Ego Gram • Setup</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
    .container { max-width: 760px; margin: 0 auto; padding: 24px; }
    h1 { font-size: 22px; margin: 8px 0 12px; }
    .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
    code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
    .muted { color: #6b7280; font-size: 14px; }
    .err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 12px; border-radius:6px; margin-top:10px; font-size: 13px; }
    a { color: #2563eb; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Welcome to Fun Alter Ego Gram</h1>
    <div class="card">
      <p>This app is running, but the database may not be ready yet.</p>
      <ol>
        <li>Create a local <code>.env</code> (copy from <code>.env.example</code>) and set DB credentials.</li>
        <li>Run migrations: <code>php artisan migrate</code></li>
        <li>Seed core data (optional): <code>php artisan db:seed</code></li>
        <li>Start the dev server: <code>php artisan serve</code> and open <a href="/">Home</a></li>
        <li><strong>Background worker:</strong> Pattern searching runs via Laravel queues. Start a worker in another terminal: <code>php artisan queue:work</code>.
          <div class="muted" style="margin-top:6px;">
            Tips:
            <ul>
              <li>If using the database queue, create tables first: <code>php artisan queue:table && php artisan migrate</code>.</li>
              <li>To use a named queue, set <code>SEARCH_QUEUE=search</code> (and then run <code>php artisan queue:work --queue=search</code>).</li>
            </ul>
          </div>
        </li>
      </ol>
      <p class="muted">Once the database is set up, this page will be replaced by the main interface.</p>
      @if(!empty($error))
        <div class="err"><strong>Details:</strong> {{ $error }}</div>
      @endif
    </div>
  </div>
</body>
</html>
