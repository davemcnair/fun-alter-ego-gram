<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search: {{ $item->name }}</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background: #f7fafc; color: #111827; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-weight: 600; font-size: 24px; margin: 8px 0 16px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); margin-bottom: 16px; }
        button { background: #2563eb; color: white; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .muted { color: #6b7280; }
        .tag { background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 9999px; font-size: 12px; }
        ul { margin: 0; padding-left: 18px; }
        li { margin: 4px 0; }
        a.link { color: #2563eb; text-decoration: none; }
        .columns { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 900px) { .columns { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
<nav style="background:#111827; color:#fff; padding:8px 12px;">
    <a href="{{ route('source-names.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Source Names</strong></a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Patterns</a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <h1>Searching Alter Egos for: {{ $item->name }}</h1>

    <div class="card">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items:center;">
            <div>
                <div>Status: <span id="status" class="tag">{{ $item->status }}</span></div>
                <div style="margin-top:6px;">Current pattern: <strong id="currentPattern">{{ $item->current_pattern ?? '—' }}</strong></div>
                <div style="margin-top:6px;">Patterns searched: <strong id="patternsSearched">{{ $item->patterns_searched }}</strong> / <strong id="patternsTotal">{{ $item->patterns_total }}</strong></div>
                <div style="margin-top:6px;">Alter egos found: <strong id="alterEgosFound">{{ $item->alteregos_found }}</strong></div>
                <div style="margin-top:6px;">Time elapsed: <strong id="timeElapsed">{{ $item->elapsed_seconds }}</strong> s</div>
            </div>
            <div style="justify-self:end; display:flex; gap:8px; align-items:center;">
                <button id="pauseBtn">Pause</button>
                <button id="resumeBtn" style="display:none; background:#10b981;">Resume</button>
                <a class="link" href="{{ route('source-names.index') }}">Back</a>
            </div>
        </div>
    </div>

        <div class="columns">
        <div class="card">
            <h3 style="margin-top:0;">Alter Egos</h3>
            <div id="alterEgoGroups">
                @php $hasAny = false; @endphp
                @foreach(($patterns ?? []) as $p)
                    @if(($p->alterEgos ?? collect())->count() > 0)
                        @php $hasAny = true; @endphp
                        <div style="margin-bottom:10px;">
                            <div><strong>{{ $p->pattern_template }}</strong> <span class="tag">rank {{ $p->popularity_rank }}</span></div>
                            <ul style="margin-top:6px;">
                                @foreach($p->alterEgos as $ae)
                                    <li>{{ $ae->phrase }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
                @if(!$hasAny)
                    <div class="muted">No alter egos yet. Processing will populate this section.</div>
                @endif
            </div>
        </div>

        <div>
            <div class="card">
                <h3 style="margin-top:0;">Token word matches</h3>
                @php
                    $groups = $matches['groups'] ?? [];
                    ksort($groups, SORT_STRING);
                @endphp
                @if(empty($groups))
                    <div class="muted">No word matches found.</div>
                @else
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                        <tr>
                            <th style="text-align:left; padding:8px; background:#f3f4f6;">Token</th>
                            <th style="text-align:left; padding:8px; background:#f3f4f6;">List</th>
                            <th style="text-align:left; padding:8px; background:#f3f4f6;">Count</th>
                            <th style="text-align:left; padding:8px; background:#f3f4f6;">Sample</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($groups as $token => $byList)
                            @php ksort($byList, SORT_STRING); @endphp
                            @foreach($byList as $listType => $items)
                                @php $count = count($items); $sample = array_slice($items, 0, 5); $rowId = md5($token.'|'.$listType); @endphp
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:8px;">{{ $token }}</td>
                                    <td style="padding:8px;">
                                        <span class="tag">{{ $listType }}</span>
                                    </td>
                                    <td style="padding:8px;">{{ $count }}</td>
                                    <td style="padding:8px;" class="muted">
                                        <div id="sample-{{ $rowId }}" style="display:block;">
                                            @foreach($sample as $it)
                                                <span style="display:inline-block; margin-right:6px;">{{ $it['word'] }}</span>
                                            @endforeach
                                            @if($count > count($sample))
                                                <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;" onclick="toggleWords('{{ $rowId }}', true)">show all ({{ $count }})</button>
                                            @endif
                                        </div>
                                        <div id="all-{{ $rowId }}" style="display:none; max-height:160px; overflow:auto;">
                                            @foreach($items as $it)
                                                <span style="display:inline-block; margin-right:6px;">{{ $it['word'] }}</span>
                                            @endforeach
                                            <div><button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;" onclick="toggleWords('{{ $rowId }}', false)">show less</button></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card">
                <h3 style="margin-top:0;">Patterns ({{ isset($patterns) ? $patterns->count() : 0 }})</h3>
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                    <tr>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Rank</th>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Pattern</th>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Status</th>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Alter egos</th>
                        <th style="text-align:left; padding:8px; background:#f3f4f6;">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse(($patterns ?? []) as $p)
                        @php $countAE = ($p->alterEgos ?? collect())->count(); @endphp
                        <tr style="border-bottom:1px solid #e5e7eb;">
                            <td style="padding:8px;">{{ $p->popularity_rank }}</td>
                            <td style="padding:8px;">
                                {{ $p->pattern_template }}
                                @if($item->current_pattern === $p->pattern_template)
                                    <span class="tag">current</span>
                                @endif
                            </td>
                            <td style="padding:8px;"><span class="tag">{{ $p->status }}</span></td>
                            <td style="padding:8px;">{{ $countAE }}</td>
                            <td style="padding:8px;">
                                @if($p->status === 'deselected')
                                    <button type="button" onclick="enablePattern({{ $item->id }}, {{ $p->id }})" style="background:#10b981;">Search</button>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="padding:8px;">No patterns were generated for this source.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>


</div>

<script>
(function(){
    const id = {{ $item->id }};
    let paused = {{ ($item->status === 'paused' || $item->status === 'idle') ? 'true' : 'false' }};
    let completed = {{ $item->status === 'completed' ? 'true' : 'false' }};
    const statusEl = document.getElementById('status');
    const curEl = document.getElementById('currentPattern');
    const pattS = document.getElementById('patternsSearched');
    const pattT = document.getElementById('patternsTotal');
    const aeFound = document.getElementById('alterEgosFound');
    const elapsed = document.getElementById('timeElapsed');
    const groupsEl = document.getElementById('alterEgoGroups');
    const pauseBtn = document.getElementById('pauseBtn');
    const resumeBtn = document.getElementById('resumeBtn');

    async function call(url, method = 'POST') {
        const res = await fetch(url, {method, headers: {'X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN': '{{ csrf_token() }}' }});
        return await res.json();
    }
    async function callJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With':'XMLHttpRequest',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(body || {})
        });
        return await res.json();
    }

    function render(p) {
        statusEl.textContent = p.status;
        curEl.textContent = p.currentPattern || '—';
        pattS.textContent = p.patternsSearched;
        pattT.textContent = p.patternsTotal;
        aeFound.textContent = p.alterEgosFound;
        elapsed.textContent = p.timeElapsed;
        // Render grouped alter egos by pattern
        if (groupsEl) {
            groupsEl.innerHTML = '';
            const groups = p.groups || [];
            if (groups.length === 0) {
                const div = document.createElement('div');
                div.className = 'muted';
                div.textContent = 'No alter egos yet. Processing will populate this section.';
                groupsEl.appendChild(div);
            } else {
                groups.forEach(function (g) {
                    const wrap = document.createElement('div');
                    wrap.style.marginBottom = '10px';
                    const head = document.createElement('div');
                    const strong = document.createElement('strong');
                    strong.textContent = g.pattern;
                    const rank = document.createElement('span');
                    rank.className = 'tag';
                    rank.style.marginLeft = '6px';
                    rank.textContent = 'rank ' + g.rank;
                    head.appendChild(strong);
                    head.appendChild(rank);
                    wrap.appendChild(head);
                    const ul = document.createElement('ul');
                    ul.style.marginTop = '6px';
                    (g.phrases || []).forEach(function (ph) {
                        const li = document.createElement('li');
                        li.textContent = ph;
                        ul.appendChild(li);
                    });
                    wrap.appendChild(ul);
                    groupsEl.appendChild(wrap);
                });
            }
        }
        paused = p.status === 'paused';
        completed = p.status === 'completed';
        pauseBtn.style.display = (!paused && !completed) ? 'inline-block' : 'none';
        resumeBtn.style.display = (paused && !completed) ? 'inline-block' : 'none';
    }

    async function stepLoop() {
        if (paused || completed) return;
        try {
            const p = await call("{{ route('source-names.run-step', $item) }}", 'POST');
            render(p);
        } catch (e) { /* ignore */ }
        if (!paused && !completed) {
            setTimeout(stepLoop, 50);
        }
    }

    // Expose as global to work with inline onclick handlers in the table
    window.toggleWords = function(id, expand) {
        const sample = document.getElementById('sample-' + id);
        const all = document.getElementById('all-' + id);
        if (!sample || !all) return;
        if (expand) { sample.style.display = 'none'; all.style.display = 'block'; }
        else { all.style.display = 'none'; sample.style.display = 'block'; }
    }
    window.toggleTokenWords = function(id, expand) {
        const sample = document.getElementById('tok-sample-' + id);
        const all = document.getElementById('tok-all-' + id);
        if (!sample || !all) return;
        if (expand) { sample.style.display = 'none'; all.style.display = 'block'; }
        else { all.style.display = 'none'; sample.style.display = 'block'; }
    }
    window.enablePattern = async function(sourceId, patternId){
        try {
            const res = await fetch("" + sourceId + "/patterns/" + patternId + "/enable".replace(/^/, '/source-names/'), {
                method: 'POST',
                headers: {'X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN': '{{ csrf_token() }}' }
            });
            const p = await res.json();
            render(p);
        } catch (e) {}
    }

    pauseBtn.addEventListener('click', async function(){
        const p = await call("{{ route('source-names.pause', $item) }}", 'POST');
        render(p);
    });
    resumeBtn.addEventListener('click', async function(){
        const p = await call("{{ route('source-names.resume', $item) }}", 'POST');
        render(p);
        if (!paused && !completed) stepLoop();
    });

    // Selection UI handlers (only present when idle)
    (function(){
        const selCard = document.getElementById('selectionCard');
        const startBtn = document.getElementById('startBtn');
        const selectAll = document.getElementById('selectAllTemplates');
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                document.querySelectorAll('.tplCheck').forEach(function(c){ c.checked = selectAll.checked; });
            });
        }
        if (startBtn) {
            startBtn.addEventListener('click', async function(){
                try {
                    const chosen = Array.from(document.querySelectorAll('.tplCheck:checked')).map(function(cb){ return cb.value; });
                    const p = await callJson("{{ route('source-names.start', $item) }}", {templates: chosen});
                    render(p);
                    if (selCard) selCard.style.display = 'none';
                    paused = false; completed = false;
                    stepLoop();
                } catch (e) { /* ignore */ }
            });
        }
    })();

    // Auto-start behavior
    const initialStatus = '{{ $item->status }}';
    if (!paused && !completed) {
        // Already running -> start the loop
        stepLoop();
    } else if (initialStatus === 'idle') {
        // If idle, auto-start with default selection (all templates)
        callJson("{{ route('source-names.start', $item) }}", { templates: [] })
            .then(function(p){
                render(p);
                paused = false; completed = false;
                stepLoop();
            })
            .catch(function(){
                // fallback to a passive progress fetch if start fails
                call("{{ route('source-names.progress', $item) }}", 'GET').then(render).catch(function(){});
            });
    } else {
        // ensure UI state reflects current status when paused/completed
        call("{{ route('source-names.progress', $item) }}", 'GET').then(render).catch(function(){});
    }
})();
</script>
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
})();
</script>
</body>
</html>
