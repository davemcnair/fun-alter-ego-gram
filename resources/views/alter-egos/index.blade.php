<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alter Ego Patterns</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        form { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .row { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: 12px; color: #4b5563; }
        input[type=text] { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; min-width: 300px; }
        .checks { display: flex; gap: 18px; align-items: center; }
        .checks label { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
        button { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .results { margin-top: 24px; }
        .meta { font-size: 14px; color: #374151; margin: 8px 0 12px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        .muted { color: #6b7280; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        .note { font-size: 12px; color: #6b7280; margin-top: 8px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Alter Ego Pattern Finder</h1>
    <form method="get" action="{{ route('alter-egos.index') }}">
        <div class="row" style="margin-bottom: 12px;">
            <div class="field">
                <label for="source">Source name</label>
                <input id="source" name="source" type="text" value="{{ old('source', $source) }}" placeholder="e.g., John Doe" required>
            </div>
        </div>
        <div class="row" style="justify-content: space-between; align-items: center;">
            <div class="checks">
                <label>
                    <input type="checkbox" name="curate_fun" value="1" {{ $curateFun ? 'checked' : '' }}>
                    Curate fun words only
                </label>
                <label>
                    <input type="checkbox" name="allow_boring" value="1" {{ $allowBoring ? 'checked' : '' }}>
                    Allow boring words
                </label>
                <label class="muted" title="Not used yet; reserved for future nearly-matching logic">
                    <input type="checkbox" name="allow_nearly" value="1" {{ $allowNearly ? 'checked' : '' }} disabled>
                    Allow nearly (coming soon)
                </label>
            </div>
            <div>
                <button type="submit">Search</button>
            </div>
        </div>
        <div class="note">Defaults: curate fun words ON, allow boring OFF, allow nearly OFF.</div>
    </form>

    @if($results)
        <div class="results">
            <div class="meta">
                Found <strong>{{ $results['meta']['count'] }}</strong> patterns for source length <strong>{{ $results['meta']['source_len'] }}</strong>
                @if(($results['meta']['boring'] ?? '') === 'excluded')
                    <span class="tag">boring=excluded</span>
                @endif
                @if(isset($results['meta']['list']))
                    <span class="tag">list={{ $results['meta']['list'] }}</span>
                @endif
            </div>
            <table>
                <thead>
                <tr>
                    <th style="width: 80px;">Rank</th>
                    <th>Pattern</th>
                    <th style="width: 140px;">Min length</th>
                </tr>
                </thead>
                <tbody>
                @forelse($results['rows'] as $row)
                    <tr>
                        <td>{{ $row['popularity_rank'] }}</td>
                        <td>{{ $row['template'] }}</td>
                        <td>
                            @if(isset($row['dyn_min']))
                                <span class="tag">dyn_min={{ $row['dyn_min'] }}</span>
                            @elseif(isset($row['min']))
                                <span class="tag">min={{ $row['min'] }}</span>
                            @elseif(($row['avail'] ?? false) === true)
                                <span class="tag">available</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No patterns matched your criteria.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
</body>
</html>
