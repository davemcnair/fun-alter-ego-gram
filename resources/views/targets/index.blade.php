<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Targets</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        form { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .row { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: 12px; color: #4b5563; }
        input[type=text] { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; min-width: 300px; }
        button { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-top: 20px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        a.btn { color: white; background: #10b981; padding: 6px 10px; border-radius: 6px; text-decoration: none; }
    </style>
</head>
<body>
<nav style="background:#111827; color:#fff; padding:8px 12px;">
    <a href="{{ route('targets.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Targets</strong></a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Patterns</a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <h1>New Search</h1>
    <form method="post" action="{{ route('targets.store') }}">
        @csrf
        <div class="row" style="margin-bottom: 12px;">
            <div class="field">
                <label for="name">Source name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="e.g., John Adam Doe" required>
                @error('name')<div style="color:#b91c1c; font-size: 12px;">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row" style="gap:12px; align-items:center;">
            <button type="submit">Create</button>
        </div>
    </form>

    <h1 style="margin-top:28px;">Recent Targets</h1>

    @if(session('status'))
        <div style="margin:10px 0; padding:10px 12px; background:#ecfeff; color:#155e75; border:1px solid #67e8f9; border-radius:6px;">{{ session('status') }}</div>
    @endif

    <form id="bulkForm" method="post" action="{{ route('targets.bulk-destroy') }}" onsubmit="return confirm('Delete selected Target names? This will remove their patterns and alter egos.');">
        @csrf
        <div style="margin: 8px 0; display:flex; gap:8px; align-items:center;">
            <label><input type="checkbox" id="selectAll"> Select all</label>
            <button type="submit" style="background:#dc2626;">Bulk Delete</button>
        </div>
        <table>
            <thead>
            <tr>
                <th></th>
                <th>Name</th>
                <th>Patterns</th>
                <th>Alter egos</th>
                <th>New matches</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($items as $s)
                <tr>
                    <td><input type="checkbox" name="ids[]" value="{{ $s->id }}" class="rowCheck"></td>
                    <td>{{ $s->name }}</td>
                    @php $total = $s->patterns()->count(); $done = $s->patterns()->where('status','done')->count(); $newCount = DB::table('target_token_signature_words as t')->where('t.target_id', $s->id)->when($s->matches_seen_at, function($q) use ($s){ $q->where('t.created_at','>', $s->matches_seen_at); })->count(); @endphp
                    <td>{{ $done }} / {{ $total }}</td>
                    <td>{{ $s->alterEgos()->count() }}</td>
                    <td>
                        @if($newCount > 0)
                            <button type="button" class="tag" id="nm-btn-{{ $s->id }}" aria-expanded="false" onclick="toggleNewMatches({{ $s->id }})">{{ $newCount }}</button>
                            <div id="nm-{{ $s->id }}" style="display:none; margin-top:6px; max-height:140px; overflow:auto; border:1px solid #e5e7eb; padding:6px; border-radius:4px;"></div>
                        @else
                            <span class="tag" style="background:#f3f4f6; color:#6b7280;">0</span>
                        @endif
                    </td>
                    <td style="display:flex; gap:6px; align-items:center;">
                        <a class="btn" href="{{ route('targets.show', $s) }}">Open</a>
                        <button type="button" onclick="processNewMatches({{ $s->id }})" {{ $newCount > 0 ? '' : 'disabled' }}>Fill with new words</button>
                        <button type="button" onclick="deleteSingle({{ $s->id }})" style="background:#dc2626;">Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No Targets yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </form>
    <div style="margin-top: 12px;">{{ $items->links() }}</div>
</div>
<script>
// Suppress a known noisy Chrome extension console error about duplicate context menu ids
(function(){
    try {
        var origError = console.error;
        console.error = function() {
            try {
                var msg = arguments.length ? String(arguments[0]) : '';
                if (msg.indexOf('runtime.lastError') !== -1 && msg.indexOf('Cannot create item with duplicate id') !== -1) {
                    return; // swallow only this specific extension noise
                }
            } catch (e) {}
            return origError.apply(console, arguments);
        };
    } catch (e) { /* ignore */ }
    // Select all handling
    (function(){
        var selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                var checks = document.querySelectorAll('.rowCheck');
                checks.forEach(function(c){ c.checked = selectAll.checked; });
            });
        }
    })();
    // Single delete helper uses the bulk form
    window.deleteSingle = function(id){
        try {
            if (!confirm('Delete this Target and all related data?')) return;
            var form = document.getElementById('bulkForm');
            if (!form) return;
            var checks = document.querySelectorAll('.rowCheck');
            checks.forEach(function(c){ c.checked = (parseInt(c.value, 10) === parseInt(id, 10)); });
            form.submit();
        } catch (e) { /* ignore */ }
    };
    // New matches UI handlers
    window.toggleNewMatches = async function(targetId){
        var panel = document.getElementById('nm-' + targetId);
        var btn = document.getElementById('nm-btn-' + targetId);
        if (!panel) return;
        var visible = panel.style.display !== 'none';
        if (visible) {
            panel.style.display = 'none';
            if (btn) btn.setAttribute('aria-expanded', 'false');
            return;
        }
        // Load list via AJAX
        panel.innerHTML = '<div>Loading…</div>';
        try {
            var res = await fetch('/targets/' + targetId + '/new-matches');
            var json = await res.json();
            if (json && json.items) {
                if (json.items.length === 0) {
                    panel.innerHTML = '<div style="color:#6b7280;">No new matches.</div>';
                } else {
                    var html = '<ul style="margin:0; padding-left: 16px;">';
                    json.items.forEach(function(it){
                        html += '<li><span class="tag" style="margin-right:6px;">' + it.token + '</span> <em>(' + it.list_type + ')</em> ' + it.word + '</li>';
                    });
                    html += '</ul>';
                    panel.innerHTML = html;
                }
            } else {
                panel.innerHTML = '<div style="color:#b91c1c;">Failed to load.</div>';
            }
        } catch (e) {
            panel.innerHTML = '<div style="color:#b91c1c;">Error loading.</div>';
        }
        panel.style.display = 'block';
        if (btn) btn.setAttribute('aria-expanded', 'true');
    };

    window.processNewMatches = async function(targetId){
        var btn = document.getElementById('nm-btn-' + targetId);
        var oldLabel = btn ? btn.textContent : '';
        if (btn) btn.textContent = '…';
        try {
            var res = await fetch('/targets/' + targetId + '/process-new-matches', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('input[name=_token]')?.value || document.querySelector('meta[name=csrf-token]')?.content || '' }});
            var json = await res.json();
            // Refresh the page to update counts
            location.reload();
        } catch (e) {
            if (btn) btn.textContent = oldLabel || '0';
            alert('Failed to process new matches');
        }
    };
  })();
  </script>
  </body>
  </html>
