<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm Anagrams</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 800px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 22px; margin: 8px 0 16px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        button, a.btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; display:inline-block; }
        button:hover, a.btn:hover { background: #1d4ed8; }
        nav.top { background:#111827; color:#fff; padding:8px 12px; }
        nav.top a { color:#fff; margin-right:10px; text-decoration:none; }
    </style>
</head>
<body>
<nav class="top">
    <a href="{{ route('source-names.index') }}">Source Names</a>
    <a href="{{ route('patterns.index') }}">Patterns</a>
    <a href="{{ route('words.index') }}"><strong>Words</strong></a>
</nav>
<div class="container">
    <h1>Confirm anagrams for token: {{ ucfirst($token_type) }}</h1>
    <div class="card">
        <p>We detected anagrams with the same letters (signature: <strong>{{ $signature }}</strong>) in token <strong>{{ $token_type }}</strong>. Choose which one should be used for <strong>search</strong>. The others will be used for <strong>phrases only</strong>.</p>
        <form method="post" action="{{ route('words.store') }}">
            @csrf
            <input type="hidden" name="confirm" value="1">
            <input type="hidden" name="word" value="{{ $candidate['word'] }}">
            <input type="hidden" name="token_type" value="{{ $token_type }}">
            <input type="hidden" name="list_type" value="{{ $candidate['list_type'] }}">
            <input type="hidden" name="signature" value="{{ $signature }}">
            <table>
                <thead>
                <tr>
                    <th>Use for search</th>
                    <th>Word</th>
                    <th>Existing?</th>
                    <th>List</th>
                </tr>
                </thead>
                <tbody>
                @php $sel = $selected_id ?? null; @endphp
                @foreach($existing as $w)
                    <tr>
                        <td><input type="radio" name="search_choice" value="existing:{{ $w->id }}" {{ ($sel === $w->id) ? 'checked' : '' }}></td>
                        <td>{{ $w->word }}</td>
                        <td><span class="tag">Yes</span></td>
                        <td>{{ $w->list_type }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td><input type="radio" name="search_choice" value="new" {{ ($sel === null || $sel === 0) ? 'checked' : '' }}></td>
                    <td>{{ $candidate['word'] }}</td>
                    <td><span class="tag" style="background:#fee2e2; color:#991b1b;">New</span></td>
                    <td>{{ $candidate['list_type'] }}</td>
                </tr>
                </tbody>
            </table>
            <div style="margin-top:12px; display:flex; gap:8px;">
                <button type="submit">Save</button>
                <a class="btn" style="background:#6b7280;" href="{{ route('words.create') }}">Back</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
