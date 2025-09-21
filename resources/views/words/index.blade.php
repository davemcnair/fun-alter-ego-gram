<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Words</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-bottom: 16px; }
        nav { background:#111827; color:#fff; padding:8px 12px; }
        nav a { color:#fff; margin-right:10px; text-decoration:none; }
        .muted { color: #6b7280; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        table { width:100%; border-collapse: collapse; }
        th, td { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px; }
        .btn { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer; text-decoration:none; display:inline-block; }
        .btn:hover { background: #1d4ed8; }
        .btn-link { background:none; border:0; color:#2563eb; cursor:pointer; padding:0; }
        .danger { background: #dc2626; }
        .danger:hover { background: #b91c1c; }
        .badge { display:inline-block; padding:2px 6px; border-radius:9999px; font-size:12px; }
        .badge-green { background:#dcfce7; color:#166534; }
        .badge-yellow { background:#fef9c3; color:#a16207; }
        .flex { display:flex; gap:8px; align-items:center; }
        .filters-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
        @media (max-width: 900px) { .filters-grid { grid-template-columns: 1fr 1fr; } }
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
        <div class="flex" style="justify-content: space-between; align-items:center;">
            <h2 style="margin:0;">Words</h2>
            <div class="flex" style="gap:8px;">
                <button id="commitBtn" class="btn" {{ $hasUncommitted ? '' : 'disabled' }} title="Commit DB words to resources" >Commit Resources</button>
                <a href="{{ route('words.create') }}" class="btn">Add word</a>
            </div>
        </div>
    </div>

    <div class="card">
        <form method="get" action="{{ route('words.index') }}" class="filters">
            <div class="filters-grid">
                <div>
                    <label class="muted" for="q">Search</label>
                    <input id="q" name="q" type="text" value="{{ $q }}" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px;">
                    <label style="display:block; margin-top:6px; font-size:14px;"><input type="checkbox" name="exact" value="1" {{ $exact ? 'checked' : '' }}> Exact match</label>
                </div>
                <div>
                    <label class="muted" for="token">Token</label>
                    <select id="token" name="token" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="">All</option>
                        @foreach($tokenOptions as $opt)
                            <option value="{{ $opt }}" {{ $token === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="muted" for="list">List</label>
                    <select id="list" name="list" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="">All</option>
                        @foreach($listOptions as $opt)
                            <option value="{{ $opt }}" {{ $list === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="muted" for="per_page">Per page</label>
                    <select id="per_page" name="per_page" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px;">
                        @foreach([10,25,50,100] as $pp)
                            <option value="{{ $pp }}" {{ (int)$perPage === (int)$pp ? 'selected' : '' }}>{{ $pp }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="muted" style="display:block;">&nbsp;</label>
                    <label style="display:block; margin-top:6px; font-size:14px;"><input type="checkbox" name="has_anags" value="1" {{ $hasAnags ? 'checked' : '' }}> Has anagrams</label>
                </div>
                <div style="align-self:end;">
                    <button type="submit" class="btn">Filter</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        @if(session('status'))
            <div class="badge badge-green" style="margin-bottom:10px;">{{ session('status') }}</div>
        @endif
        <div style="overflow:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Word</th>
                        <th>Token</th>
                        <th>List</th>
                        <th>Signature</th>
                        <th>Anagrams</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $w)
                        <tr data-id="{{ $w->id }}">
                            <td>{{ $w->id }}</td>
                            <td>{{ $w->word }}</td>
                            <td>{{ $w->token_type }}</td>
                            <td>
                                {{ $w->list_type }}
                                @if(strtolower($w->list_type) === 'fun')
                                    <span class="badge badge-yellow">fun</span>
                                @endif
                            </td>
                            <td>{{ $w->signature }}</td>
                            <td>
                                @php $hasA = (bool)($hasAnagsMap[$w->id] ?? false); @endphp
                                @if($hasA)
                                    <details>
                                        <summary>Show ({{ count($anagsListMap[$w->id] ?? []) }})</summary>
                                        <div>
                                            @foreach(($anagsListMap[$w->id] ?? []) as $a)
                                                <div class="flex" style="gap:6px; margin:3px 0;">
                                                    <span>{{ $a['word'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @else
                                    <span class="muted">None</span>
                                @endif
                            </td>
                            <td>
                                <a class="btn" href="{{ route('words.edit', $w) }}">Edit</a>
                                <form method="post" action="{{ route('words.destroy', $w) }}" style="display:inline;" onsubmit="return confirm('Delete this word?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn danger">Delete</button>
                                </form>
                                @php $funAble = in_array(strtolower($w->token_type), ['forename','surname'], true); @endphp
                                @if($funAble && strtolower($w->list_type) !== 'fun')
                                    <button class="btn-link js-promote" data-id="{{ $w->id }}">Promote to fun</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:10px;">
            {{ $items->links() }}
        </div>
    </div>
</div>
<script>
(function(){
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(body || {})
        }).then(r => r.json());
    }

    const commitBtn = document.getElementById('commitBtn');
    if (commitBtn) {
        commitBtn.addEventListener('click', function(){
            if (this.hasAttribute('disabled')) return;
            this.setAttribute('disabled', 'disabled');
            this.textContent = 'Committing...';
            postJson('{{ route('words.commit-resources') }}', {}).then(res => {
                if (res && res.ok) {
                    alert('Committed ' + (res.committed_count || 0) + ' word(s). Backup: ' + (res.backup || 'n/a'));
                    location.reload();
                } else {
                    alert((res && res.error) || 'Commit failed');
                    commitBtn.removeAttribute('disabled');
                    commitBtn.textContent = 'Commit Resources';
                }
            }).catch(() => {
                alert('Commit failed');
                commitBtn.removeAttribute('disabled');
                commitBtn.textContent = 'Commit Resources';
            });
        });
    }

    document.querySelectorAll('.js-promote').forEach(btn => {
        btn.addEventListener('click', function(){
            const id = this.dataset.id;
            const url = '{{ route('api.words.promote', ['word' => 'WORD_ID']) }}'.replace('WORD_ID', String(id));
            postJson(url, {}).then(res => {
                if (res && res.ok) {
                    location.reload();
                } else {
                    alert(res.error || 'Failed to promote');
                }
            }).catch(() => alert('Failed to promote'));
        });
    });
})();
</script>
</body>
</html>
