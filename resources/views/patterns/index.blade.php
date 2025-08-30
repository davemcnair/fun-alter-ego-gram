<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patterns</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 1000px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        form.tools { background: #fff; border-radius: 8px; padding: 12px; box-shadow: 0 1px 2px rgba(0,0,0,.06); display:flex; gap:10px; align-items:center; }
        input[type=text] { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; min-width: 280px; }
        button, a.btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; display:inline-block; }
        button:hover, a.btn:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-top: 12px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        .row-actions { display:flex; gap:6px; }
    </style>
</head>
<body>
<nav class="top" style="background:#111827; color:#fff; padding:8px 12px;">
    <a href="{{ route('source-names.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Source Names</a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Patterns</strong></a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; gap: 10px; flex-wrap:wrap;">
        <h1>Patterns</h1>
        <div></div>
    </div>

    @if(session('status'))
        <div style="margin:10px 0; padding:10px 12px; background:#ecfeff; color:#155e75; border:1px solid #67e8f9; border-radius:6px;">{{ session('status') }}</div>
    @endif

    <form class="tools" method="get" action="{{ route('patterns.index') }}">
        <div>
            <input type="text" name="q" value="{{ $q }}" placeholder="Filter by template...">
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <button type="submit">Search</button>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>Rank</th>
            <th>Template</th>
            <th>Min len</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $p)
            <tr>
                <td>{{ $p->popularity_rank }}</td>
                <td>{{ $p->template }}</td>
                <td>{{ $p->min_total_length }}</td>
                <td class="row-actions">
                    <a class="btn" href="{{ route('patterns.edit', $p) }}">Edit</a>
                    <form method="post" action="{{ route('patterns.destroy', $p) }}" onsubmit="return confirm('Delete this pattern?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="background:#dc2626;">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7">No patterns found.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top: 12px;">{{ $items->links() }}</div>
</div>
</body>
</html>
