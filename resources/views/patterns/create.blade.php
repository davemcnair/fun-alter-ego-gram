<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Pattern</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 720px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        form { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        input[type=text], input[type=number] { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; }
        label { font-size: 12px; color: #4b5563; }
        button, a.btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; display:inline-block; }
        button:hover, a.btn:hover { background: #1d4ed8; }
        .flags { display:flex; gap:16px; flex-wrap:wrap; }
        .error { color:#b91c1c; font-size: 12px; }
    </style>
</head>
<body>
<nav style="background:#111827; color:#fff; padding:8px 12px;">
    <a href="{{ route('targets.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Source Names</a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Patterns</strong></a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; gap: 10px; flex-wrap:wrap;">
        <h1>New Pattern</h1>
        <a class="btn" href="{{ route('patterns.index') }}">Back</a>
    </div>

    <form method="post" action="{{ route('patterns.store') }}">
        @csrf
        <div class="field">
            <label for="template">Template</label>
            <input id="template" name="template" type="text" value="{{ old('template', $pattern->template) }}" required>
            @error('template')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="row" style="margin-top:10px;">
            <div class="field">
                <label for="popularity_rank">Popularity rank</label>
                <input id="popularity_rank" name="popularity_rank" type="number" min="1" value="{{ old('popularity_rank', $pattern->popularity_rank ?? 1) }}" required>
                @error('popularity_rank')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="min_total_length">Min total length</label>
                <input id="min_total_length" name="min_total_length" type="number" min="0" value="{{ old('min_total_length', $pattern->min_total_length ?? 0) }}" required>
                @error('min_total_length')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="forename_count">Forename count</label>
                <input id="forename_count" name="forename_count" type="number" min="0" value="{{ old('forename_count', $pattern->forename_count ?? 0) }}" required>
                @error('forename_count')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="surname_count">Surname count</label>
                <input id="surname_count" name="surname_count" type="number" min="0" value="{{ old('surname_count', $pattern->surname_count ?? 1) }}" required>
                @error('surname_count')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="flags" style="margin-top:10px;">
            <label><input type="checkbox" name="has_title" value="1" {{ old('has_title', $pattern->has_title ?? false) ? 'checked' : '' }}> title</label>
            <label><input type="checkbox" name="has_initials" value="1" {{ old('has_initials', $pattern->has_initials ?? false) ? 'checked' : '' }}> initials</label>
            <label><input type="checkbox" name="has_prefix" value="1" {{ old('has_prefix', $pattern->has_prefix ?? false) ? 'checked' : '' }}> prefix</label>
            <label><input type="checkbox" name="has_suffix" value="1" {{ old('has_suffix', $pattern->has_suffix ?? false) ? 'checked' : '' }}> suffix</label>
            <label><input type="checkbox" name="has_honorific" value="1" {{ old('has_honorific', $pattern->has_honorific ?? false) ? 'checked' : '' }}> honorific</label>
        </div>
        <div style="margin-top:12px;">
            <button type="submit">Create</button>
        </div>
    </form>
</div>
</body>
</html>
