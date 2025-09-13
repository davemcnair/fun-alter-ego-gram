<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Word</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 760px; margin: 0 auto; padding: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-bottom: 16px; }
        nav { background:#111827; color:#fff; padding:8px 12px; }
        nav a { color:#fff; margin-right:10px; text-decoration:none; }
        .btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer; text-decoration:none; display:inline-block; }
        .btn:hover { background: #1d4ed8; }
        .field { margin-bottom: 12px; }
        label { display:block; font-size: 14px; color: #6b7280; margin-bottom: 6px; }
        input[type=text], select { width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; }
        .error { color:#dc2626; font-size: 14px; }
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
        <h2 style="margin-top:0;">Add Word</h2>
        <form method="post" action="{{ route('words.store') }}">
            @csrf
            <div class="field">
                <label for="word">Word</label>
                <input type="text" id="word" name="word" value="{{ old('word', $word->word ?? '') }}">
                @error('word')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="token_type">Token type</label>
                <input type="text" id="token_type" name="token_type" value="{{ old('token_type', $word->token_type ?? '') }}" placeholder="e.g. forename, surname, initial">
                @error('token_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="list_type">List type</label>
                <input type="text" id="list_type" name="list_type" value="{{ old('list_type', $word->list_type ?? '') }}" placeholder="e.g. fun, ok, adj, noun">
                @error('list_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <button type="submit" class="btn">Save</button>
                <a class="btn" href="{{ route('words.index') }}" style="background:#6b7280;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
