<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm anagrams</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 760px; margin: 0 auto; padding: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-bottom: 16px; }
        nav { background:#111827; color:#fff; padding:8px 12px; }
        nav a { color:#fff; margin-right:10px; text-decoration:none; }
        .btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer; text-decoration:none; display:inline-block; }
        .btn:hover { background: #1d4ed8; }
        .muted { color: #6b7280; }
        .list { background:#f9fafb; border:1px solid #e5e7eb; padding:10px; border-radius:8px; }
        .radio { margin:6px 0; }
    </style>
</head>
<body>
<nav>
    <a href="{{ route('targets.index') }}"><strong>Source Names</strong></a>
    <a href="{{ route('patterns.index') }}">Patterns</a>
    <a href="{{ route('words.index') }}">Words</a>
</nav>
<div class="container">
    <div class="card">
        <h2 style="margin-top:0;">Confirm anagram set</h2>
        <p class="muted">We found existing words with the same signature ({{ $signature }}) for token "{{ $token_type }}". Choose which word should be used for search for this anagram set.</p>
        <div class="list">
            <form method="post" action="{{ route('words.store') }}">
                @csrf
                <input type="hidden" name="confirm" value="1">
                <input type="hidden" name="token_type" value="{{ $token_type }}">
                <input type="hidden" name="word" value="{{ $candidate['word'] ?? '' }}">
                <input type="hidden" name="list_type" value="{{ $candidate['list_type'] ?? '' }}">

                <div class="radio">
                    <label>
                        <input type="radio" name="search_choice" value="new" {{ empty($selected_id) ? 'checked' : '' }}>
                        Use new word "{{ $candidate['word'] ?? '' }}" as search representative
                    </label>
                </div>

                <div style="margin-top:12px;">
                    <button type="submit" class="btn">Confirm</button>
                    <a href="{{ route('words.index') }}" class="btn" style="background:#6b7280;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
