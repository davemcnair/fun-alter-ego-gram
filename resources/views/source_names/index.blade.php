<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Source Names</title>
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
    <a href="{{ route('source-names.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Source Names</strong></a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Patterns</a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <h1>New Search</h1>
    <form method="post" action="{{ route('source-names.store') }}">
        @csrf
        <div class="row" style="margin-bottom: 12px;">
            <div class="field">
                <label for="name">Source name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="e.g., John Adam Doe" required>
                @error('name')<div style="color:#b91c1c; font-size: 12px;">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row" style="gap:12px; align-items:center;">
            <button type="button" id="previewBtn" style="background:#6b7280;">Find matching templates</button>
            <button type="submit">Create</button>
        </div>
        <div id="templatesBox" style="display:none; margin-top:12px;">
            <div style="margin-bottom:8px; display:flex; gap:8px; align-items:center;">
                <label><input type="checkbox" id="selectAllTemplates"> Select all</label>
                <span class="tag" id="tplCount">0</span>
            </div>
            <div style="max-height:220px; overflow:auto; border:1px solid #e5e7eb; border-radius:6px;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                    <tr>
                        <th style="text-align:left; padding:8px; background:#f3f4f6; width:36px;"></th>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Rank</th>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Pattern</th>
                    </tr>
                    </thead>
                    <tbody id="tplRows"></tbody>
                </table>
            </div>
        </div>
    </form>

    <h1 style="margin-top:28px;">Recent Sources</h1>

    @if(session('status'))
        <div style="margin:10px 0; padding:10px 12px; background:#ecfeff; color:#155e75; border:1px solid #67e8f9; border-radius:6px;">{{ session('status') }}</div>
    @endif

    <form id="bulkForm" method="post" action="{{ route('source-names.bulk-destroy') }}" onsubmit="return confirm('Delete selected source names? This will remove their patterns and alter egos.');">
        @csrf
        <div style="margin: 8px 0; display:flex; gap:8px; align-items:center;">
            <label><input type="checkbox" id="selectAll"> Select all</label>
            <button type="submit" style="background:#dc2626;">Bulk Delete</button>
        </div>
        <table>
            <thead>
            <tr>
                <th></th>
                <th>ID</th>
                <th>Name</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Alter egos</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($items as $s)
                <tr>
                    <td><input type="checkbox" name="ids[]" value="{{ $s->id }}" class="rowCheck"></td>
                    <td>{{ $s->id }}</td>
                    <td>{{ $s->name }}</td>
                    <td><span class="tag">{{ $s->status }}</span></td>
                    <td>{{ $s->patterns_searched }} / {{ $s->patterns_total }}</td>
                    <td>{{ $s->alter_egos_count ?? 0 }}</td>
                    <td style="display:flex; gap:6px; align-items:center;">
                        <a class="btn" href="{{ route('source-names.show', $s) }}">Open</a>
                        <button type="button" onclick="deleteSingle({{ $s->id }})" style="background:#dc2626;">Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">No sources yet.</td></tr>
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
            if (!confirm('Delete this source and all related data?')) return;
            var form = document.getElementById('bulkForm');
            if (!form) return;
            var checks = document.querySelectorAll('.rowCheck');
            checks.forEach(function(c){ c.checked = (parseInt(c.value, 10) === parseInt(id, 10)); });
            form.submit();
        } catch (e) { /* ignore */ }
    };
    // Preview patterns list
    (function(){
        var btn = document.getElementById('previewBtn');
        var box = document.getElementById('templatesBox');
        var rows = document.getElementById('tplRows');
        var selectAll = document.getElementById('selectAllTemplates');
        var countEl = document.getElementById('tplCount');
        function renderTemplates(list){
            rows.innerHTML = '';
            var c = 0;
            (list.rows || []).forEach(function(r){
                var tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #e5e7eb';
                var td0 = document.createElement('td'); td0.style.padding='8px';
                var cb = document.createElement('input'); cb.type='checkbox'; cb.className='tplCheck'; cb.value=r.template; cb.checked = (parseInt(r.popularity_rank, 10) <= 100);
                // Hidden field on submit
                cb.addEventListener('change', syncHiddenInputs);
                td0.appendChild(cb);
                var td1 = document.createElement('td'); td1.style.padding='8px'; td1.textContent = r.popularity_rank;
                var td2 = document.createElement('td'); td2.style.padding='8px'; td2.textContent = r.template;
                tr.appendChild(td0); tr.appendChild(td1); tr.appendChild(td2);
                rows.appendChild(tr); c++;
            });
            countEl.textContent = c + ' templates';
            syncHiddenInputs();
            box.style.display = 'block';
        }
        function syncHiddenInputs(){
            // Remove previous hidden inputs
            document.querySelectorAll('input[name="templates[]"]').forEach(function(n){ n.parentNode.removeChild(n); });
            // Create hidden inputs for checked templates
            var form = document.querySelector('form[action*="source-names"]');
            if (!form) return;
            var chosen = Array.from(document.querySelectorAll('.tplCheck:checked')).map(function(cb){ return cb.value; });
            chosen.forEach(function(t){
                var h = document.createElement('input'); h.type='hidden'; h.name='templates[]'; h.value=t; form.appendChild(h);
            });
        }
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                document.querySelectorAll('.tplCheck').forEach(function(c){ c.checked = selectAll.checked; });
                syncHiddenInputs();
            });
        }
        if (btn) {
            btn.addEventListener('click', function(){
                try {
                    var name = document.getElementById('name').value || '';
                    if (name.replace(/[^a-z]/ig,'').length < 5) { alert('Please enter at least 5 letters.'); return; }
                    fetch("{{ route('patterns.preview') }}?" + new URLSearchParams({name: name}), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                        .then(function(r){ return r.json(); })
                        .then(function(j){ if (j && j.rows) renderTemplates(j); })
                        .catch(function(){});
                } catch (e) {}
            });
        }
    })();
  })();
  </script>
  </body>
  </html>
