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
            .drag-over { outline: 2px dashed #93c5fd; }
        .dragging { opacity: 0.6; }
    </style>
</head>
<body>
<nav class="top" style="background:#111827; color:#fff; padding:8px 12px;">
    <a href="{{ route('targets.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Source Names</a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Patterns</strong></a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; gap: 10px; flex-wrap:wrap;">
        <h1>Patterns</h1>
        <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" onclick="exportPatterns()" title="Save current order and settings to resources/patterns">
                Save to resources
            </button>
        </div>
    </div>

    @if(session('status'))
        <div style="margin:10px 0; padding:10px 12px; background:#ecfeff; color:#155e75; border:1px solid #67e8f9; border-radius:6px;">{{ session('status') }}</div>
    @endif

    <form class="tools" method="get" action="{{ route('patterns.index') }}">
        <div>
            @php $opts = ['' => 'All tokens', 'title' => 'Title', 'forename' => 'Forename', 'initials' => 'Initials', 'prefix' => 'Prefix', 'surname' => 'Surname', 'suffix' => 'Suffix', 'honorific' => 'Honorific']; @endphp
            <select name="token" style="padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; min-width: 200px;">
                @foreach($opts as $val => $label)
                    <option value="{{ $val }}" {{ (isset($token) && $token === $val) ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit">Filter</button>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th style="width:36px;"></th>
            <th>Rank</th>
            <th>Type</th>
            <th>Template</th>
            <th>Example</th>
            <th>Min len</th>
        </tr>
        </thead>
        <tbody id="pattern-tbody">
        @forelse($items as $p)
            <tr draggable="true" data-id="{{ $p->id }}">
                <td style="cursor:grab;">⋮⋮</td>
                <td class="rank-cell">{{ $p->popularity_rank }}</td>
                <td>
                    <select onchange="setType({{ $p->id }}, this.value, this)" data-prev="{{ $p->pattern_type }}" style="padding:6px 8px; border:1px solid #d1d5db; border-radius:6px;">
                        @foreach(['standard','longer','exotic'] as $opt)
                            <option value="{{ $opt }}" {{ $p->pattern_type === $opt ? 'selected' : '' }}>{{ ucfirst($opt) }}</option>
                        @endforeach
                    </select>
                </td>
                <td>{{ $p->template }}</td>
                <td>{{ $p->example }}</td>
                <td>{{ $p->min_total_length }}</td>
            </tr>
        @empty
            <tr><td colspan="6">No patterns found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>
(function(){
    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(body || {})
        });
        if (!res.ok) {
            const t = await res.text().catch(function(){ return ''; });
            throw new Error('HTTP ' + res.status + ' ' + res.statusText + (t ? (': ' + t) : ''));
        }
        return await res.json();
    }
    window.setType = async function(id, value, sel){
        try {
            if (!id || !value || !sel) return;
            const prev = sel.getAttribute('data-prev') || '';
            sel.disabled = true;
            const url = '{{ route('patterns.update-type', ['pattern' => '__ID__']) }}'.replace('__ID__', String(id));
            const resp = await post(url, { pattern_type: String(value) });
            if (resp && resp.ok) {
                sel.setAttribute('data-prev', String(value));
            } else {
                if (prev) sel.value = prev;
                alert('Failed to update type.');
            }
        } catch (e) {
            try {
                const prev = sel.getAttribute('data-prev') || '';
                if (prev) sel.value = prev;
            } catch (err) {}
            alert('Error updating type: ' + (e && e.message ? e.message : 'Unknown error'));
        } finally {
            sel.disabled = false;
        }
    }

    // Drag & drop reordering
    const tbody = document.getElementById('pattern-tbody');
    if (tbody) {
        let dragEl = null;
        tbody.addEventListener('dragstart', function(e){
            const tr = e.target.closest('tr');
            if (!tr) return;
            dragEl = tr;
            tr.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        tbody.addEventListener('dragend', function(e){
            const tr = e.target.closest('tr');
            if (tr) tr.classList.remove('dragging');
            tbody.querySelectorAll('tr').forEach(function(r){ r.classList.remove('drag-over'); });
            dragEl = null;
            renumberRanks();
            submitOrder();
        });
        tbody.addEventListener('dragover', function(e){
            e.preventDefault();
            const afterEl = getDragAfterElement(tbody, e.clientY);
            const dragging = tbody.querySelector('tr.dragging');
            if (!dragging) return;
            if (afterEl == null) {
                tbody.appendChild(dragging);
            } else {
                tbody.insertBefore(dragging, afterEl);
            }
        });
        function getDragAfterElement(container, y) {
            const els = [...container.querySelectorAll('tr:not(.dragging)')];
            return els.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
        function renumberRanks() {
            let i = 1;
            tbody.querySelectorAll('tr').forEach(function(tr){
                const cell = tr.querySelector('.rank-cell');
                if (cell) cell.textContent = i++;
            });
        }
        async function submitOrder() {
            const ids = Array.from(tbody.querySelectorAll('tr')).map(function(tr){ return parseInt(tr.getAttribute('data-id') || '0', 10); }).filter(Boolean);
            try {
                await post('{{ route('patterns.reorder') }}', { ids: ids });
            } catch (e) {
                alert('Failed to save order: ' + (e && e.message ? e.message : 'Unknown error'));
            }
        }
    }

    // Export button
    window.exportPatterns = async function() {
        try {
            const resp = await post('{{ route('patterns.export') }}', {});
            if (resp && resp.ok) {
                alert('Saved ' + resp.count + ' patterns to ' + resp.file);
            } else {
                alert('Export failed.');
            }
        } catch (e) {
            alert('Export failed: ' + (e && e.message ? e.message : 'Unknown error'));
        }
    }
})();
</script>
</body>
</html>
