<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anagrammer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .muted { color: #6b7280; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        a.button { display: inline-block; margin-top: 12px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; padding: 10px 14px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Anagrammer</h1>
    <div class="card">
        <p class="muted">Source:</p>
        <p><strong>{{ $source }}</strong></p>
        @if(isset($results['meta']))
            <p>Patterns found: <strong>{{ $results['meta']['count'] ?? 0 }}</strong>; source length <strong>{{ $results['meta']['source_len'] ?? '?' }}</strong></p>
        @endif
        <p class="muted">Options:
            <span class="tag">curate_fun={{ $curate_fun ? '1' : '0' }}</span>
            <span class="tag">allow_boring={{ $allow_boring ? '1' : '0' }}</span>
            <span class="tag">allow_nearly={{ $allow_nearly ? '1' : '0' }}</span>
        </p>
        <p class="muted">This is a minimal placeholder page for anagramming. Further generation of anagrams can be added using the Anagrammer service.</p>
        <p><a href="{{ route('alter-egos.index', ['source' => $source]) }}" class="button">Back to Alter Ego Patterns</a></p>
    </div>
</div>
</body>
</html>
