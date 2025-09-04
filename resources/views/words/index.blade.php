<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Words</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 1000px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        form.tools { background: #fff; border-radius: 8px; padding: 12px; box-shadow: 0 1px 2px rgba(0,0,0,.06); display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        input[type=text] { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; min-width: 220px; }
        button, a.btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; display:inline-block; }
        button:hover, a.btn:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-top: 12px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        .row-actions { display:flex; gap:6px; }
        nav.top { background:#111827; color:#fff; padding:8px 12px; }
        nav.top a { color:#fff; margin-right:10px; text-decoration:none; }
        .tag { background:#eef2ff; color:#3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
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
        <h1>Words</h1>
        <div style="display:flex; gap:8px; align-items:center;">
            <a class="btn" style="background:#10b981;" href="{{ route('words.create') }}">New Word</a>
        </div>
    </div>

    @if(session('status'))
        <div style="margin:10px 0; padding:10px 12px; background:#ecfeff; color:#155e75; border:1px solid #67e8f9; border-radius:6px;">{{ session('status') }}</div>
    @endif

    <form class="tools" method="get" action="{{ route('words.index') }}">
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="text" name="q" value="{{ $q }}" placeholder="Search word...">
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" name="exact" value="1" {{ !empty($exact) ? 'checked' : '' }}> Exact
            </label>
            <select name="token" style="padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; min-width: 160px;">
                <option value="">All tokens</option>
                @foreach(($tokenOptions ?? []) as $opt)
                    <option value="{{ $opt }}" {{ ($token === $opt) ? 'selected' : '' }}>{{ ucfirst($opt) }}</option>
                @endforeach
            </select>
            <select name="list" style="padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; min-width: 160px;">
                <option value="">All lists</option>
                @foreach(($listOptions ?? []) as $opt)
                    <option value="{{ $opt }}" {{ ($list === $opt) ? 'selected' : '' }}>{{ ucfirst($opt) }}</option>
                @endforeach
            </select>
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" name="has_anags" value="1" {{ !empty($hasAnags) ? 'checked' : '' }}> Only with anagrams
            </label>
            <button type="submit">Filter</button>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>Word</th>
            <th>Token</th>
            <th>List</th>
            <th>Has anags</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $w)
            @php $has = (bool) (($hasAnagsMap[$w->id] ?? false)); $anags = $anagsListMap[$w->id] ?? []; $rowId = 'row-'.$w->id; @endphp
            <tr>
                <td>
                    {{ $w->word }}
                    @php $has = (bool) (($hasAnagsMap[$w->id] ?? false)); @endphp
                    @if($has)
                        @if(!empty($w->use_for_search))
                            <span class="tag" style="margin-left:6px; background:#e0f2fe; color:#0369a1;">Search</span>
                        @else
                            <span class="tag" style="margin-left:6px; background:#fef3c7; color:#92400e;">Build</span>
                        @endif
                    @endif
                </td>
                <td>{{ $w->token_type }}</td>
                <td>{{ $w->list_type }}</td>
                <td>
                    @if($has)
                        <span class="tag" style="background:#dcfce7; color:#065f46;">Yes</span>
                        @if(empty($w->use_for_search))
                            <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0; margin-left:8px;" onclick="makeSearch({{ $w->id }})">make search</button>
                        @endif
                        <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0; margin-left:8px;" onclick="toggleAnags('{{ $rowId }}', true)">show</button>
                    @else
                        <span class="tag" style="background:#fee2e2; color:#991b1b;">No</span>
                    @endif
                </td>
                <td class="row-actions">
                    <a class="btn" href="{{ route('words.edit', $w) }}">Edit</a>
                    <form method="post" action="{{ route('words.destroy', $w) }}" onsubmit="return confirm('Delete this word?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="background:#dc2626;">Delete</button>
                    </form>
                </td>
            </tr>
            @if($has)
                <tr id="{{ $rowId }}" style="display:none; background:#f9fafb;">
                    <td colspan="5">
                        <div><strong>Anagrams:</strong> <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;" onclick="toggleAnags('{{ $rowId }}', false)">hide</button></div>
                        <div style="margin-top:6px; display:flex; gap:8px; flex-wrap:wrap;">
                            @foreach($anags as $a)
                                <span class="tag">{{ $a['word'] }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @endif
        @empty
            <tr><td colspan="5">No words found.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top: 12px;">{{ $items->links() }}</div>

    <script>
        function toggleAnags(id, show){
            try{ var el = document.getElementById(id); if(!el) return; el.style.display = show ? 'table-row' : 'none'; } catch(e){}
        }
        async function makeSearch(id){
            try{
                const res = await fetch("" + id + "/toggle-search".replace(/^/, '/words/'), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':'{{ csrf_token() }}'} });
                const j = await res.json();
                if (j && j.ok) { window.location.reload(); }
                else { alert('Failed to update search representative' + (j && j.error ? (': ' + j.error) : '')); }
            }catch(e){ alert('Error updating search representative.'); }
        }
    </script>
</div>
</body>
</html>
