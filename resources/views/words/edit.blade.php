<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Word</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 720px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        form { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        input[type=text] { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; }
        label { font-size: 12px; color: #4b5563; }
        button, a.btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; display:inline-block; }
        button:hover, a.btn:hover { background: #1d4ed8; }
        .error { color:#b91c1c; font-size: 12px; }
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
    <div style="display:flex; justify-content:space-between; align-items:center; gap: 10px; flex-wrap:wrap;">
        <h1>Edit Word</h1>
        <a class="btn" href="{{ route('words.index') }}">Back</a>
    </div>

    <form method="post" action="{{ route('words.update', $word) }}">
        @csrf
        @method('PUT')
        <div class="field">
            <label for="word">Word</label>
            <input id="word" name="word" type="text" value="{{ old('word', $word->word) }}" required>
            @error('word')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="row" style="margin-top:10px;">
            <div class="field">
                <label for="token_type">Token type</label>
                <input id="token_type" name="token_type" type="text" value="{{ old('token_type', $word->token_type) }}" required>
                @error('token_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="list_type">List type</label>
                <input id="list_type" name="list_type" type="text" value="{{ old('list_type', $word->list_type) }}" required>
                @error('list_type')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>
        <div style="margin-top:12px;">
            <button type="submit">Save</button>
        </div>
    </form>
</div>
</body>
</html>
