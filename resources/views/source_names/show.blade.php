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
        .highlight-fun { background: #fff3cd; color: #92400e; padding: 0 3px; border-radius: 3px; }
        .highlight-match { background: #dcfce7; color: #065f46; padding: 0 3px; border-radius: 3px; cursor: pointer; }
        .highlight-active { outline: 2px solid #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,0.2) inset; }
        .filter-pill { display:inline-flex; align-items:center; gap:6px; background:#ecfeff; color:#0e7490; border:1px solid #a5f3fc; border-radius:9999px; padding:2px 8px; font-size:12px; }
        .filter-pill button { background:none; color:#0e7490; border:0; cursor:pointer; padding:0; }
        .star-btn { background: none; border: 0; font-size: 16px; cursor: pointer; color: #9ca3af; padding:0 4px; }
        .star-btn.starred { color: #f59e0b; }
        .dragging-word { opacity: 0.6; }
        .drop-target { outline: 2px dashed #93c5fd; }
        .ph-block { display: inline-flex; align-items: center; gap: 4px; }
        .ph-part { display: inline-block; padding: 0 2px; cursor: grab; }
        .ph-part.dragging-word { opacity: 0.7; }
        .ph-sep { opacity: 0.6; user-select: none; }
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
                <div id="patternsRow" style="margin-top:6px;">Patterns searched: <strong id="patternsSearched">0</strong> / <strong id="patternsTotal">0</strong></div>
                <div style="margin-top:6px;">Alter egos found: <strong id="alterEgosFound">{{$alterEgosCount}}</strong> <span class="muted">in <span id="patternsWithAE">0</span> patterns</span></div>
                <div style="margin-top:6px;">Fun alter egos found: <strong id="funAlterEgosFound">0</strong> <span class="muted">in <span id="patternsWithFunAE">0</span> patterns</span></div>
            </div>
          </div>
    </div>

        <div class="columns">
        <div class="card">
            <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
                Alter Egos
                <span style="margin-left:auto; display:flex; align-items:center; gap:12px; font-weight:normal; font-size:14px;">
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" id="onlyStarredToggle"> Favourites only
                    </label>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" id="onlyFunToggle"> Only fun
                    </label>
                </span>
            </h3>
            <div id="wordFilterStatus" class="muted" style="margin:4px 0 8px 0; font-size:14px;"></div>
            <div id="starredSection" style="margin-bottom:10px; display:none;">
                <div><strong>Starred</strong> <span class="tag"><span id="starredCount">0</span></span></div>
                <ul id="starredList" style="margin-top:6px;"></ul>
            </div>
            <div id="alterEgoGroups">
                @php $hasAny = false; @endphp
                @foreach(($patternsLive ?? []) as $p)
                    @php
                        $alterEgos = isset($p['alterEgos']) ? $p['alterEgos'] : [];
                        $count = is_iterable($alterEgos) ? count($alterEgos) : (is_object($alterEgos) && method_exists($alterEgos,'count') ? $alterEgos->count() : 0);
                    @endphp
                    @if($count > 0)
                        @php $hasAny = true; @endphp
                        <div style="margin-bottom:10px;">
                            <div><strong>{{ $p['template'] ?? '' }}</strong> <span class="tag">{{ $count }} found</span></div>
                            <ul style="margin-top:6px;">
                                @foreach($alterEgos as $ae)
                                    @php $phrase = is_array($ae) ? ($ae['phrase'] ?? '') : (is_object($ae) ? ($ae->phrase ?? '') : ''); @endphp
                                    <li>{{ $phrase }}</li>
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
                <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">Token word matches
                    <label style="margin-left:auto; font-weight:normal; display:flex; align-items:center; gap:6px; font-size:14px;">
                        <input type="checkbox" id="onlyUsedToggle" checked> Only used
                    </label>
                </h3>
                @php
                    $groups = is_array($wordMatches) ? $wordMatches : [];
                    if (!empty($groups)) {
                        uksort($groups, function($a, $b){
                            if ($a === 'surname' && $b !== 'surname') return -1;
                            if ($b === 'surname' && $a !== 'surname') return 1;
                            return strcasecmp((string)$a, (string)$b);
                        });
                    }
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
                                <tr id="row-{{ $rowId }}" data-rowid="{{ $rowId }}" data-token="{{ $token }}" data-list="{{ $listType }}" data-total="{{ $count }}" style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:8px;">{{ $token }}</td>
                                    <td style="padding:8px;">
                                        <span class="tag">{{ $listType }}</span>
                                    </td>
                                    <td style="padding:8px;"><span id="count-{{ $rowId }}">{{ $count }}</span></td>
                                    <td style="padding:8px;" class="muted">
                                        <div id="sample-{{ $rowId }}" style="display:none;">
                                            @foreach($sample as $it)
                                                @php $wid = (int)($it['id'] ?? 0); $w = (string)($it['word'] ?? ''); @endphp
                                                @if(in_array($token, ['forename','surname']) && $listType === 'ok')
                                                    <span class="tok-word" data-token="{{ $token }}" data-word="{{ $w }}" style="display:inline-block; margin-right:6px; cursor:pointer; text-decoration:underline;" onclick="promoteOkWord({{ $wid }}, '{{ addslashes($w) }}')" title="Promote to fun">{{ $w }}</span>
                                                    <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;" onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')"></button>
                                                @elseif(in_array($token, ['forename','surname']))
                                                    <span class="tok-word" data-token="{{ $token }}" data-word="{{ $w }}" style="display:inline-block; margin-right:6px; cursor:pointer; color:#2563eb; text-decoration:underline;" onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')">{{ $w }}</span>
                                                @else
                                                    <span class="tok-word" data-token="{{ $token }}" data-word="{{ $w }}" style="display:inline-block; margin-right:6px;">{{ $w }}</span>
                                                @endif
                                            @endforeach
                                            @if($count > count($sample))
                                                <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;" onclick="toggleWords('{{ $rowId }}', true)">show all ({{ $count }})</button>
                                            @endif
                                        </div>
                                        <div id="all-{{ $rowId }}" style="display:block; max-height:160px; overflow:auto;">
                                            @foreach($items as $it)
                                                @php $wid = (int)($it['id'] ?? 0); $w = (string)($it['word'] ?? ''); @endphp
                                                @if(in_array($token, ['forename','surname']) && $listType === 'ok')
                                                    <span class="tok-word" data-token="{{ $token }}" data-word="{{ $w }}" style="display:inline-block; margin-right:6px; cursor:pointer; text-decoration:underline;" onclick="promoteOkWord({{ $wid }}, '{{ addslashes($w) }}')" title="Promote to fun">{{ $w }}</span>
                                                    <button type="button" class="link" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;" onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')"></button>
                                                @elseif(in_array($token, ['forename','surname']))
                                                    <span class="tok-word" data-token="{{ $token }}" data-word="{{ $w }}" style="display:inline-block; margin-right:6px; cursor:pointer; color:#2563eb; text-decoration:underline;" onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')">{{ $w }}</span>
                                                @else
                                                    <span class="tok-word" data-token="{{ $token }}" data-word="{{ $w }}" style="display:inline-block; margin-right:6px;">{{ $w }}</span>
                                                @endif
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

        </div>
    </div>


</div>

<script>
// Build sets of known fun words and all matched words from server-provided matches (lowercased)
@php
    $funSurname = [];
    $funForename = [];
    $allSurname = [];
    $allForename = [];
    $groupsForFun = is_array($wordMatches) ? $wordMatches : [];
    // collect fun
    if (isset($groupsForFun['surname']['fun'])) {
        foreach ($groupsForFun['surname']['fun'] as $it) {
            $w = strtolower((string)($it['word'] ?? ''));
            if ($w !== '') { $funSurname[$w] = true; }
        }
    }
    if (isset($groupsForFun['forename']['fun'])) {
        foreach ($groupsForFun['forename']['fun'] as $it) {
            $w = strtolower((string)($it['word'] ?? ''));
            if ($w !== '') { $funForename[$w] = true; }
        }
    }
    // collect all matched (both ok and fun)
    if (isset($groupsForFun['surname'])) {
        foreach ($groupsForFun['surname'] as $lt => $items) {
            foreach ($items as $it) { $w = strtolower((string)($it['word'] ?? '')); if ($w !== '') { $allSurname[$w] = true; } }
        }
    }
    if (isset($groupsForFun['forename'])) {
        foreach ($groupsForFun['forename'] as $lt => $items) {
            foreach ($items as $it) { $w = strtolower((string)($it['word'] ?? '')); if ($w !== '') { $allForename[$w] = true; } }
        }
    }
@endphp
const FUN_SURNAME = new Set(@json(array_keys($funSurname)));
const FUN_FORENAME = new Set(@json(array_keys($funForename)));
const ALL_SURNAME = new Set(@json(array_keys($allSurname)));
const ALL_FORENAME = new Set(@json(array_keys($allForename)));

(function(){
    const id = {{ $item->id }};
    let paused = false;
    let completed = {{ $item->status === 'completed' ? 'true' : 'false' }};
    const statusEl = document.getElementById('status');
    const wordFilterStatus = document.getElementById('wordFilterStatus');
    const pattRow = document.getElementById('patternsRow');
    const showPatternSelectionLink = document.getElementById('showPatternSelection');
    const pattS = document.getElementById('patternsSearched');
    const pattT = document.getElementById('patternsTotal');
    const aeFound = document.getElementById('alterEgosFound');
    const funAeFound = document.getElementById('funAlterEgosFound');
    const patternsWithAE = document.getElementById('patternsWithAE');
    const patternsWithFunAE = document.getElementById('patternsWithFunAE');
    const groupsEl = document.getElementById('alterEgoGroups');
    const pauseBtn = document.getElementById('pauseBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const onlyFunToggle = document.getElementById('onlyFunToggle');
    const onlyStarredToggle = document.getElementById('onlyStarredToggle');
    const onlyUsedToggle = document.getElementById('onlyUsedToggle');
    const starredSection = document.getElementById('starredSection');
    const starredList = document.getElementById('starredList');
    const starredCount = document.getElementById('starredCount');
    const URL_STAR = "{{ route('source-names.star', $item) }}";
    const URL_UNSTAR = "{{ route('source-names.unstar', $item) }}";
    let onlyFun = false;
    let onlyStarred = false;
    let onlyUsed = true;
    let renderedOnce = false;
    let wordFilter = { word: null, token: null };
    let starredSet = new Set();

    if (onlyFunToggle) {
        try {
            // Restore previous preference
            const saved = localStorage.getItem('onlyFunToggle');
            if (saved === '1') { onlyFun = true; onlyFunToggle.checked = true; }
            onlyFunToggle.addEventListener('change', function(){
                onlyFun = !!onlyFunToggle.checked;
                try { localStorage.setItem('onlyFunToggle', onlyFun ? '1' : '0'); } catch (e) {}
                // Re-render using last known progress if available
                call("{{ route('source-names.progress', $item) }}", 'GET').then(render).catch(function(){});
            });
        } catch (e) { /* ignore */ }
    }

    if (onlyStarredToggle) {
        try {
            const savedS = localStorage.getItem('onlyStarredToggle');
            if (savedS === '1') { onlyStarred = true; onlyStarredToggle.checked = true; }
            onlyStarredToggle.addEventListener('change', function(){
                onlyStarred = !!onlyStarredToggle.checked;
                try { localStorage.setItem('onlyStarredToggle', onlyStarred ? '1' : '0'); } catch (e) {}
                call("{{ route('source-names.progress', $item) }}", 'GET').then(render).catch(function(){});
            });
        } catch (e) { /* ignore */ }
    }

    if (onlyUsedToggle) {
        try {
            // Restore previous preference for Only used (default true)
            const savedUsed = localStorage.getItem('onlyUsedToggle');
            if (savedUsed === '0') { onlyUsed = false; onlyUsedToggle.checked = false; }
            onlyUsedToggle.addEventListener('change', function(){
                onlyUsed = !!onlyUsedToggle.checked;
                try { localStorage.setItem('onlyUsedToggle', onlyUsed ? '1' : '0'); } catch (e) {}
                // Refresh UI against latest progress to recompute used sets
                call("{{ route('source-names.progress', $item) }}", 'GET').then(render).catch(function(){});
            });
        } catch (e) { /* ignore */ }
    }

    function parseBlocks(template) {
        // Returns array of blocks: {type:'surname', count:n} or {type:'other', name:string, count:n}
        const re = /\{([a-z]+)(?::(\d+))?\}/ig;
        const blocks = [];
        let m;
        while ((m = re.exec(template)) !== null) {
            const name = (m[1] || '').toLowerCase();
            const count = Math.max(1, parseInt(m[2] || '1', 10));
            if (name === 'surname') {
                // merge with previous surname block if consecutive
                const last = blocks[blocks.length - 1];
                if (last && last.type === 'surname') {
                    last.count += count;
                } else {
                    blocks.push({type: 'surname', count});
                }
            } else {
                blocks.push({type: 'other', name, count});
            }
        }
        return blocks;
    }

    function hasAnyFunToken(phrase, template) {
        try {
            const tokens = String(phrase).split(' ').filter(t => t.length > 0);
            const blocks = parseBlocks(String(template || ''));
            let ti = 0;
            for (let b of blocks) {
                if (b.type === 'surname') {
                    const c = Math.max(1, parseInt(b.count || 1, 10));
                    for (let k = 0; k < c; k++) {
                        const tok = tokens[ti++] || '';
                        const parts = tok.split('-');
                        for (let part of parts) {
                            if (FUN_SURNAME.has(String(part).toLowerCase())) return true;
                        }
                    }
                } else {
                    const c = Math.max(1, parseInt(b.count || 1, 10));
                    for (let k = 0; k < c; k++) {
                        const tok = tokens[ti++] || '';
                        if (b.name === 'forename') {
                            if (FUN_FORENAME.has(String(tok).toLowerCase())) return true;
                        }
                    }
                }
            }
        } catch (e) { /* ignore */ }
        return false;
    }

    // Generate all unique permutations of an array of strings
    function permute(parts) {
        const res = [];
        const used = Array(parts.length).fill(false);
        const cur = [];
        const seen = new Set();
        function backtrack() {
            if (cur.length === parts.length) {
                const key = cur.join('\u0001');
                if (!seen.has(key)) { seen.add(key); res.push(cur.slice()); }
                return;
            }
            for (let i = 0; i < parts.length; i++) {
                if (used[i]) continue;
                used[i] = true;
                cur.push(parts[i]);
                backtrack();
                cur.pop();
                used[i] = false;
            }
        }
        backtrack();
        return res;
    }

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]); });
    }
    function highlightToken(word, tokenName) {
        const wStr = String(word);
        if (tokenName === 'surname') {
            const rawParts = wStr.split('-');
            return rawParts.map(function(part){
                const low = part.toLowerCase();
                return FUN_SURNAME.has(low) ? ('<span class="highlight-fun">' + escHtml(part) + '</span>') : escHtml(part);
            }).join('-');
        }
        if (tokenName === 'forename') {
            const low = wStr.toLowerCase();
            return FUN_FORENAME.has(low) ? ('<span class="highlight-fun">' + escHtml(wStr) + '</span>') : escHtml(wStr);
        }
        return escHtml(wStr);
    }

    function computePhraseVariants(phrase, template) {
        // Helpers
        function hyphenVariants(word) {
            // Do not permute hyphen-internal parts; treat the token as atomic
            return [String(word)];
        }
        function cartesian(arrays, sep) {
            let acc = [''];
            arrays.forEach(function(a){
                const next = [];
                acc.forEach(function(prefix){
                    a.forEach(function(v){
                        next.push(prefix ? (prefix + sep + v) : v);
                    });
                });
                acc = next;
            });
            return acc;
        }
        function buildBlockVariants(tokensSlice) {
            const perTok = tokensSlice.map(hyphenVariants);
            if (perTok.length === 1) return perTok[0];
            // Permute the order of tokens within the block, and combine internal variants
            const idx = perTok.map((_, i) => i);
            const orderings = permute(idx);
            const results = [];
            const seen = new Set();
            orderings.forEach(function(ord){
                const arrays = ord.map(i => perTok[i]);
                const combos = cartesian(arrays, ' ');
                combos.forEach(function(s){
                    const key = s.toLowerCase();
                    if (!seen.has(key)) { seen.add(key); results.push(s); }
                });
            });
            return results;
        }
        // Build all variants by permuting hyphenated multi-part tokens and multi-token blocks (forename and surname)
        const tokens = String(phrase).split(' ').filter(t => t.length > 0);
        const blocks = parseBlocks(String(template || ''));
        let ti = 0;
        const segments = [];
        for (let b of blocks) {
            const c = Math.max(1, parseInt(b.count || 1, 10));
            if (b.type === 'surname') {
                const slice = [];
                for (let k = 0; k < c; k++) { slice.push(tokens[ti++] || ''); }
                const variants = buildBlockVariants(slice);
                segments.push({name:'surname', variants});
            } else {
                if (b.name === 'forename') {
                    const slice = [];
                    for (let k = 0; k < c; k++) { slice.push(tokens[ti++] || ''); }
                    const variants = buildBlockVariants(slice);
                    segments.push({name:'forename', variants});
                } else {
                    for (let k = 0; k < c; k++) {
                        const tok = tokens[ti++] || '';
                        segments.push({name:b.name || 'other', variants: [tok]});
                    }
                }
            }
        }
        // Cartesian product of segments' variants
        let acc = [''];
        const names = [];
        segments.forEach(function(seg){
            const next = [];
            acc.forEach(function(prefix){
                seg.variants.forEach(function(v){
                    next.push((prefix ? (prefix + ' ') : '') + v);
                });
            });
            acc = next;
            names.push(seg.name);
        });
        // Global case-insensitive de-duplication of full phrase variants
        const uniqAcc = [];
        const seenAll = new Set();
        acc.forEach(function(s){
            const key = String(s).toLowerCase();
            if (!seenAll.has(key)) { seenAll.add(key); uniqAcc.push(s); }
        });
        return { variants: uniqAcc, names: names };
    }

    let __phraseIdSeq = 0;
    function highlightPhraseDisplay(phrase, template) {
        try {
            const tokens = String(phrase).split(' ').filter(t => t.length > 0);
            const blocks = parseBlocks(String(template || ''));
            const out = [];
            let ti = 0;
            for (let b of blocks) {
                const c = Math.max(1, parseInt(b.count || 1, 10));
                if (b.type === 'surname' || b.name === 'forename') {
                    // For multi-token blocks (e.g., surname:2), render each token as a draggable part separated by spaces
                    const tname = b.type === 'surname' ? 'surname' : 'forename';
                    const slice = [];
                    for (let k = 0; k < c; k++) { slice.push(tokens[ti++] || ''); }
                    // Build inner HTML: each token is a .ph-part; keep token text as-is (including any hyphens inside)
                    const inner = slice.map(function(tok){
                        const html = highlightToken(tok, tname);
                        return '<span class="ph-part" data-token="'+tname+'" data-word="'+escAttr(tok)+'" draggable="true">'+html+'</span>';
                    }).join('<span class="ph-sep"> </span>');
                    out.push('<span class="ph-block" data-token="'+tname+'">'+inner+'</span>');
                } else {
                    for (let k = 0; k < c; k++) {
                        const tok = tokens[ti++] || '';
                        out.push(highlightToken(tok, b.name));
                    }
                }
            }
            const baseHtml = out.join(' ');
            return baseHtml;
        } catch (e) {
            return phrase;
        }
    }

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

    function escAttr(s) {
        return String(s).replace(/["'\\]/g, function(c){
            if (c === '"') return '&quot;';
            if (c === "'") return '&#39;';
            return '\\' + c;
        });
    }

    function starBtnHTML(phrase) {
        const isStar = starredSet.has(phrase);
        const cls = 'star-btn' + (isStar ? ' starred' : '');
        const title = isStar ? 'Unstar' : 'Star';
        const icon = isStar ? '★' : '☆';
        const pEsc = phrase.replace(/'/g, "\\'");
        return '<button type="button" class="' + cls + '" title="' + title + '" onclick="window.toggleStar(\'' + pEsc + '\')">' + icon + '</button>';
    }

    window.toggleStar = async function(phrase){
        try {
            const isStar = starredSet.has(phrase);
            const url = isStar ? URL_UNSTAR : URL_STAR;
            const res = await callJson(url, { phrase: phrase });
            if (res && res.ok) {
                render(res);
            } else {
                alert('Failed to update favourite.');
            }
        } catch (e) {
            alert('Error updating favourite.');
        }
    }

    async function promoteOkWord(id, word) {
        try {
            if (!id) return;
            if (!confirm('Promote ' + word + ' to fun?')) return;
            const url = '{{ route('words.promote', ['word' => 'WORD_ID']) }}'.replace('WORD_ID', String(id));
            const res = await callJson(url, {});
            if (res && res.ok) {
                // easiest, reliable refresh so counts and fun filters update
                window.location.reload();
            }
        } catch (e) { /* ignore */ }
    }

    function phraseContainsWord(phrase, template, word, tokenName) {
        if (!word) return true;
        try {
            const tokens = String(phrase).split(' ').filter(t => t.length > 0);
            const blocks = parseBlocks(String(template || ''));
            let ti = 0;
            const wlow = String(word).toLowerCase();
            for (let b of blocks) {
                const c = Math.max(1, parseInt(b.count || 1, 10));
                if (b.type === 'surname') {
                    for (let k = 0; k < c; k++) {
                        const tok = tokens[ti++] || '';
                        const parts = tok.split('-');
                        for (let part of parts) {
                            if ((!tokenName || tokenName === 'surname') && part.toLowerCase() === wlow) return true;
                        }
                    }
                } else {
                    for (let k = 0; k < c; k++) {
                        const tok = tokens[ti++] || '';
                        if (b.name === 'forename') {
                            if ((!tokenName || tokenName === 'forename') && tok.toLowerCase() === wlow) return true;
                        } else {
                            // other tokens not part of this filter requirement
                        }
                    }
                }
            }
        } catch (e) { /* ignore */ }
        return false;
    }

    function appendGroupToDom(g) {
        const all = Array.isArray(g.phrases) ? g.phrases.slice() : [];
        const list1 = onlyFun ? all.filter(function(ph){ return hasAnyFunToken(ph, g.pattern); }) : all;
        const list1b = onlyStarred ? list1.filter(function(ph){ return starredSet.has(ph); }) : list1;
        const list = (wordFilter.word ? list1b.filter(function(ph){ return phraseContainsWord(ph, g.pattern, wordFilter.word, wordFilter.token); }) : list1b);
        if (list.length === 0) return false; // nothing to append when filtered
        // Remove empty message if present
        if (groupsEl && groupsEl.firstChild && groupsEl.firstChild.classList && groupsEl.firstChild.classList.contains('muted')) {
            groupsEl.innerHTML = '';
        }
        const wrap = document.createElement('div');
        wrap.style.marginBottom = '10px';
        const head = document.createElement('div');
        const strong = document.createElement('strong');
        strong.textContent = g.pattern;
        const rank = document.createElement('span');
        rank.className = 'tag';
        rank.style.marginLeft = '6px';
        rank.textContent = list.length + ' found';
        head.appendChild(strong);
        head.appendChild(rank);
        wrap.appendChild(head);
        const ul = document.createElement('ul');
        ul.style.marginTop = '6px';
        list.forEach(function (ph) {
            const li = document.createElement('li');
            li.setAttribute('data-phrase', ph);
            li.setAttribute('data-pattern', g.pattern);
            li.innerHTML = starBtnHTML(ph) + ' ' + '<span class="phrase-text">' + highlightPhraseDisplay(ph, g.pattern) + '</span>' + ' <button type="button" class="link save-order-btn" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0; display:none;" title="Save this order">save</button>';
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        groupsEl.appendChild(wrap);
        return true;
    }

    function setWordFilter(word, token) {
        try {
            const w = String(word || '').trim();
            const t = String(token || '').toLowerCase();
            if (!w) { wordFilter = {word:null, token:null}; }
            else {
                if (wordFilter.word && wordFilter.word.toLowerCase() === w.toLowerCase() && wordFilter.token === t) {
                    wordFilter = { word: null, token: null };
                } else {
                    wordFilter = { word: w, token: (t === 'surname' || t === 'forename') ? t : null };
                }
            }
            // Re-render based on latest progress to refresh highlighting
            call("{{ route('source-names.progress', $item) }}", 'GET').then(render).catch(function(){});
        } catch (e) { /* ignore */ }
    }
    function clearWordFilter(){ setWordFilter('', ''); }
    window.setWordFilter = setWordFilter;
    window.clearWordFilter = clearWordFilter;

    function updateWordFilterStatus(){
        if (!wordFilterStatus) return;
        if (wordFilter.word) {
            const label = (wordFilter.token ? (wordFilter.token + ': ') : '') + wordFilter.word;
            wordFilterStatus.innerHTML = '<span class="filter-pill">Filtering by ' + escHtml(label) + ' <button type="button" onclick="window.clearWordFilter()">clear</button></span>';
        } else {
            wordFilterStatus.textContent = '';
        }
    }

    function computeUsedWordSetsFromGroups(groups) {
        const usedForename = new Set();
        const usedSurname = new Set();
        const byToken = {};
        try {
            const arr = Array.isArray(groups) ? groups : [];
            arr.forEach(function(g){
                const allPhrases = Array.isArray(g.phrases) ? g.phrases : [];
                // Apply same filtering as display list
                const filtered1 = onlyFun ? allPhrases.filter(function(ph){ return hasAnyFunToken(ph, g.pattern); }) : allPhrases;
                const filtered1b = onlyStarred ? filtered1.filter(function(ph){ return starredSet.has(ph); }) : filtered1;
                const filtered = (wordFilter.word ? filtered1b.filter(function(ph){ return phraseContainsWord(ph, g.pattern, wordFilter.word, wordFilter.token); }) : filtered1b);
                const blocks = parseBlocks(String(g.pattern || ''));
                filtered.forEach(function(ph){
                    const tokens = String(ph).split(' ').filter(t => t.length > 0);
                    let ti = 0;
                    for (let b of blocks) {
                        const c = Math.max(1, parseInt(b.count || 1, 10));
                        if (b.type === 'surname') {
                            for (let k = 0; k < c; k++) {
                                const tok = tokens[ti++] || '';
                                const low = tok.toLowerCase();
                                if (low) {
                                    usedSurname.add(low);
                                    byToken['surname'] = byToken['surname'] || new Set();
                                    byToken['surname'].add(low);
                                }
                                // also include hyphen parts
                                const parts = tok.split('-');
                                for (let part of parts) {
                                    const pl = String(part).toLowerCase();
                                    if (pl) {
                                        usedSurname.add(pl);
                                        byToken['surname'] = byToken['surname'] || new Set();
                                        byToken['surname'].add(pl);
                                    }
                                }
                            }
                        } else if (b.name === 'forename') {
                            for (let k = 0; k < c; k++) {
                                const tok = tokens[ti++] || '';
                                const low = tok.toLowerCase();
                                if (low) {
                                    usedForename.add(low);
                                    byToken['forename'] = byToken['forename'] || new Set();
                                    byToken['forename'].add(low);
                                }
                            }
                        } else {
                            for (let k = 0; k < c; k++) {
                                const tok = tokens[ti++] || '';
                                const low = String(tok).toLowerCase();
                                const tname = String(b.name || 'other').toLowerCase();
                                if (low) {
                                    byToken[tname] = byToken[tname] || new Set();
                                    byToken[tname].add(low);
                                }
                            }
                        }
                    }
                });
            });
        } catch (e) { /* ignore */ }
        return { forename: usedForename, surname: usedSurname, byToken: byToken };
    }

    function refreshTokenWordsUI(used) {
        try {
            const byToken = used.byToken || {};
            const nodes = document.querySelectorAll('.tok-word');
            nodes.forEach(function(n){
                const token = String(n.getAttribute('data-token') || '').toLowerCase();
                const word = String(n.getAttribute('data-word') || '').toLowerCase();
                const set = byToken[token];
                const isUsed = !!(set && set.has(word));
                n.classList.remove('highlight-match', 'highlight-active');
                // Reset clickability by default
                n.style.cursor = '';
                n.onclick = null;
                if (isUsed) {
                    n.classList.add('highlight-match');
                    // make clickable to filter only for forename/surname
                    if (token === 'forename' || token === 'surname') {
                        n.style.cursor = 'pointer';
                        n.onclick = function(){ window.setWordFilter(n.getAttribute('data-word') || '', token); };
                    }
                }
                // active indicator if matches current filter
                if (wordFilter.word && (token === wordFilter.token) && wordFilter.word.toLowerCase() === word) {
                    n.classList.add('highlight-active');
                }
            });
        } catch (e) { /* ignore */ }
    }

    function applyOnlyUsedFilterToTable(used) {
        try {
            const byToken = used.byToken || {};
            const rows = document.querySelectorAll('tr[data-rowid]');
            rows.forEach(function(row){
                const rowId = row.getAttribute('data-rowid') || '';
                const token = String(row.getAttribute('data-token') || '').toLowerCase();
                const total = parseInt(row.getAttribute('data-total') || '0', 10) || 0;
                const set = byToken[token] || null;
                const allContainer = document.getElementById('all-' + rowId);
                const sampleContainer = document.getElementById('sample-' + rowId);
                let visibleCount = 0;
                const showWord = function(wordLower) {
                    if (!onlyUsed) return true;
                    return !!(set && set.has(wordLower));
                };
                // Iterate both containers
                [allContainer, sampleContainer].forEach(function(container){
                    if (!container) return;
                    const words = container.querySelectorAll('.tok-word');
                    words.forEach(function(n){
                        const wordLower = String(n.getAttribute('data-word') || '').toLowerCase();
                        const shouldShow = showWord(wordLower);
                        // Prefer hiding the wrapper (.tok-item) if present to avoid whitespace gaps
                        const wrapper = (n.closest ? n.closest('.tok-item') : null);
                        if (wrapper) {
                            wrapper.style.display = shouldShow ? '' : 'none';
                        } else {
                            // Fallback: hide/show the word and its adjacent action button
                            n.style.display = shouldShow ? '' : 'none';
                            const sib = n.nextElementSibling;
                            if (sib && sib.tagName === 'BUTTON' && sib.classList.contains('link')) {
                                sib.style.display = shouldShow ? '' : 'none';
                            }
                        }
                        if (container === allContainer && shouldShow) {
                            visibleCount++;
                        }
                    });
                });
                // Update count and row visibility
                const countEl = document.getElementById('count-' + rowId);
                if (countEl) {
                    countEl.textContent = onlyUsed ? String(visibleCount) : String(total);
                }
                row.style.display = (onlyUsed && visibleCount === 0) ? 'none' : '';
            });
        } catch (e) { /* ignore */ }
    }

    function updateStarredUI(list) {
        try {
            const arr = Array.isArray(list) ? list : [];
            starredSet = new Set(arr);
            if (starredSection) {
                if (arr.length === 0) {
                    starredSection.style.display = 'none';
                } else {
                    starredSection.style.display = 'block';
                    if (starredCount) starredCount.textContent = String(arr.length);
                    if (starredList) {
                        starredList.innerHTML = '';
                        arr.forEach(function(ph){
                            const li = document.createElement('li');
                            li.innerHTML = starBtnHTML(ph) + ' ' + escHtml(ph);
                            starredList.appendChild(li);
                        });
                    }
                }
            }
        } catch (e) { /* ignore */ }
    }


    function render(p) {
        const status = (p && p.item && p.item.status) ? p.item.status : (p.status || '');
        statusEl.textContent = status;
        // Build groups from new backend payload
        const groupsArr = Array.isArray(p && p.patternsLive) ? (p.patternsLive.map(function(pl){
            const tmpl = pl && (pl.template || (pl.signatureIndexedPatterns && pl.signatureIndexedPatterns[0] && pl.signatureIndexedPatterns[0].pattern) || '');
            const phrases = Array.isArray(pl && pl.alterEgos) ? pl.alterEgos.map(function(ae){ return (ae && ae.phrase) ? ae.phrase : (ae && ae['phrase'] ? ae['phrase'] : ''); }).filter(Boolean) : [];
            return { pattern: tmpl || '', phrases: phrases };
        }).filter(function(g){ return g.phrases.length > 0; })) : [];
        // Update starred UI/state early so phrase star buttons reflect it
        updateStarredUI((p && p.starred) ? p.starred : []);
        // Hide patterns searched row when completed; show otherwise
        if (pattRow) {
            const isCompleted = String(status || '').toLowerCase() === 'completed';
            pattRow.style.display = isCompleted ? 'none' : '';
        }
        // Maintain counts when not completed
        if (String(status || '').toLowerCase() !== 'completed') {
            pattS.textContent = String((p && p.patternsProcessedCount) || 0);
            pattT.textContent = String((p && p.patternsCount) || 0);
        }
        // Alter egos counts
        aeFound.textContent = String((p && p.alterEgosCount) || 0);
        // Full recompute of fun/AE counts each render
        if (patternsWithAE) patternsWithAE.textContent = String(groupsArr.length);
        try {
            let funCount = 0;
            let funPatterns = 0;
            groupsArr.forEach(function(g){
                const phrases = Array.isArray(g.phrases) ? g.phrases : [];
                let hasFunInGroup = false;
                phrases.forEach(function(ph){
                    const isFun = hasAnyFunToken(ph, g.pattern);
                    if (isFun) { funCount++; hasFunInGroup = true; }
                });
                if (hasFunInGroup) funPatterns++;
            });
            if (funAeFound) funAeFound.textContent = String(funCount);
            if (patternsWithFunAE) patternsWithFunAE.textContent = String(funPatterns);
        } catch (e) { if (funAeFound) funAeFound.textContent = '0'; if (patternsWithFunAE) patternsWithFunAE.textContent = '0'; }
        // Render grouped alter egos by pattern (full re-render each poll to reflect background updates)
        if (groupsEl) {
            groupsEl.innerHTML = '';
            const groups = groupsArr;
            if (groups.length === 0) {
                const div = document.createElement('div');
                div.className = 'muted';
                div.textContent = 'No alter egos yet. Processing will populate this section.';
                groupsEl.appendChild(div);
            } else {
                groups.forEach(function (g) { appendGroupToDom(g); });
            }
            renderedOnce = true;
        }
        // Update word filter status UI
        updateWordFilterStatus();
        // Highlight used words in the token match column and make them clickable
        try {
            const used = computeUsedWordSetsFromGroups(groupsArr);
            refreshTokenWordsUI(used);
            applyOnlyUsedFilterToTable(used);
        } catch (e) { /* ignore */ }
        completed = String(status || '').toLowerCase() === 'completed';
    }

    // Fixed poll delay (5s)
    function getPollDelayMs() {
        return 5000;
    }

    async function stepLoop() {
        if (completed) return;
        try {
            // Async poll progress; background workers should process jobs
            const p = await call("{{ route('source-names.progress', $item) }}", 'GET');
            render(p);
        } catch (e) { /* ignore */ }
        if (!completed) {
            setTimeout(stepLoop, getPollDelayMs());
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
    window.togglePhraseVariants = function(id, expand) {
        const sample = document.getElementById('ph-sample-' + id);
        const all = document.getElementById('ph-all-' + id);
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

    // Selection UI and manual start removed because start is handled in store()
    (function(){ /* no-op */ })();

    // Auto-start behavior
    const initialStatus = '{{ $item->status }}';
    if (!completed) {
        // Already running -> start the loop
        stepLoop();
    } else if (initialStatus === 'idle') {
        // If idle, do not auto-start (store handles starting); just fetch progress once
        call("{{ route('source-names.progress', $item) }}", 'GET')
            .then(function(p){ render(p); })
            .catch(function(){});
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

// Enable drag-and-drop reordering for multi-part tokens within phrases (forename/surname hyphen chains)
(function(){
  try {
    function enableBlockDnD(block){
      // Attach a change detector for showing the save button when phrase order differs
      const li = block.closest && block.closest('li');
      const phraseSpan = li ? li.querySelector('.phrase-text') : null;
      const saveBtn = li ? li.querySelector('.save-order-btn') : null;
      const orig = li ? (li.getAttribute('data-phrase') || '') : '';
      if (!block) return;
      let dragging = null;
      block.addEventListener('dragstart', function(e){
        const el = e.target && e.target.closest ? e.target.closest('.ph-part') : null;
        if (!el) return;
        dragging = el;
        el.classList.add('dragging-word');
        e.dataTransfer.effectAllowed = 'move';
      });
      {{--block.addEventListener('dragend', function(){--}}
      {{--  if (dragging) dragging.classList.remove('dragging-word');--}}
      {{--  dragging = null;--}}
      {{--  try {--}}
      {{--    if (li && phraseSpan) {--}}
      {{--      const current = phraseSpan.textContent.trim();--}}
      {{--      if (current && current !== orig) {--}}
      {{--        // Auto-persist the reorder without requiring a separate save click--}}
      {{--        callJson("{{ route('source-names.rephrase', $item) }}", { from: orig, to: current })--}}
      {{--          .then(function(res){--}}
      {{--            if (res && res.ok) {--}}
      {{--              render(res);--}}
      {{--            } else {--}}
      {{--              alert('Failed to save phrase order.');--}}
      {{--              // Revert UI back to original phrase for clarity--}}
      {{--              phraseSpan.textContent = orig;--}}
      {{--            }--}}
      {{--          })--}}
      {{--          .catch(function(){--}}
      {{--            alert('Error saving phrase order.');--}}
      {{--            phraseSpan.textContent = orig;--}}
      {{--          });--}}
      {{--      }--}}
      {{--    }--}}
      {{--  } catch (e) { /* ignore */ }--}}
      {{--});--}}
      {{--block.addEventListener('dragover', function(e){--}}
      {{--  if (!dragging) return;--}}
      {{--  e.preventDefault();--}}
      {{--  const after = getAfter(block, e.clientX, e.clientY);--}}
      {{--  if (!after) {--}}
      {{--    block.appendChild(dragging);--}}
      {{--    // keep separators consistent--}}
      {{--  } else {--}}
      {{--    // Insert before the target or before its preceding separator--}}
      {{--    const ref = after;--}}
      {{--    block.insertBefore(dragging, ref);--}}
      {{--  }--}}
      {{--  // Normalize separators between parts--}}
      {{--  normalizeSeparators(block);--}}
      {{--});--}}
      // initial cleanup
      normalizeSeparators(block);
    }
    function normalizeSeparators(block){
      // Ensure there is a single space separator between each .ph-part
      // Remove existing explicit separators first
      Array.from(block.querySelectorAll('.ph-sep')).forEach(function(n){ n.remove(); });
      const parts = Array.from(block.querySelectorAll('.ph-part'));
      for (let i = 0; i < parts.length; i++) {
        if (i > 0) {
          const sep = document.createElement('span');
          sep.className = 'ph-sep';
          sep.textContent = ' ';
          block.insertBefore(sep, parts[i]);
        }
      }
    }
    function getAfter(container, x, y){
      const els = Array.from(container.querySelectorAll('.ph-part:not(.dragging-word)'));
      let closest = {offset: Number.NEGATIVE_INFINITY, element: null};
      els.forEach(function(child){
        const box = child.getBoundingClientRect();
        const offset = y - (box.top + box.height/2);
        if (offset < 0 && offset > closest.offset) {
          closest = {offset: offset, element: child};
        }
      });
      return closest.element;
    }
    // Attach to any existing blocks now and also when content is updated
    function initAll(){
      document.querySelectorAll('.ph-block').forEach(enableBlockDnD);
    }
    // Run now and re-run after renders that replace groups
    initAll();
    // Observe changes within #alterEgoGroups to re-enable DnD after rerender
    const groupsEl = document.getElementById('alterEgoGroups');
    if (groupsEl && 'MutationObserver' in window) {
      const mo = new MutationObserver(function(){
        try {
          // Avoid feedback loop: disconnect while initializing, then reconnect
          mo.disconnect();
          // Debounce to next tick to batch multiple mutations
          setTimeout(function(){
            initAll();
            mo.observe(groupsEl, { childList: true, subtree: true });
          }, 0);
        } catch (e) {
          // Best effort fallback
          try { mo.observe(groupsEl, { childList: true, subtree: true }); } catch (e2) {}
        }
      });
      mo.observe(groupsEl, { childList: true, subtree: true });
    }
  } catch (e) { /* ignore */ }
})();

// Enable drag-and-drop reordering for token matches when token can appear multiple times (forename/surname)
(function(){
  try {
    const rows = document.querySelectorAll('tr[data-rowid][data-token][data-list]');
    rows.forEach(function(row){
      const token = String(row.getAttribute('data-token') || '').toLowerCase();
      const listType = String(row.getAttribute('data-list') || '').toLowerCase();
      if (!(token === 'forename' || token === 'surname')) return; // only tokens with n:>1
      const rowId = String(row.getAttribute('data-rowid'));
      const container = document.getElementById('all-' + rowId);
      if (!container) return;
      // Wrap each word and its optional adjacent action button into a draggable group
      Array.from(container.querySelectorAll('.tok-word')).forEach(function(wordEl){
        const wrap = document.createElement('span');
        wrap.className = 'tok-item';
        wrap.style.display = 'inline-block';
        wrap.style.marginRight = '6px';
        wordEl.parentNode.insertBefore(wrap, wordEl);
        wrap.appendChild(wordEl);
        // If the next sibling is the action button (link), move it into the wrapper
        if (wrap.nextSibling && wrap.nextSibling.tagName === 'BUTTON') {
          wrap.appendChild(wrap.nextSibling);
        }
      });
      // Apply saved order if available
      const key = 'order:' + token + ':' + listType;
      try {
        const saved = JSON.parse(localStorage.getItem(key) || '[]');
        if (Array.isArray(saved) && saved.length) {
          const byWord = {};
          Array.from(container.querySelectorAll('.tok-item')).forEach(function(el){
            const wEl = el.querySelector('.tok-word');
            const w = wEl ? String(wEl.getAttribute('data-word') || '').toLowerCase() : '';
            if (!w) return;
            byWord[w] = byWord[w] || [];
            byWord[w].push(el);
          });
          container.innerHTML = '';
          saved.forEach(function(w){
            const low = String(w || '').toLowerCase();
            const arr = byWord[low] || [];
            if (arr.length) { container.appendChild(arr.shift()); }
          });
          // Append any remaining not in saved
          Object.keys(byWord).forEach(function(k){ byWord[k].forEach(function(el){ container.appendChild(el); }); });
        }
      } catch (e) {}

      let dragging = null;
      container.addEventListener('dragstart', function(e){
        const target = e.target;
        const el = target && target.classList && target.classList.contains('tok-item') ? target : (target && target.closest ? target.closest('.tok-item') : null);
        if (!el) return;
        dragging = el;
        el.classList.add('dragging-word');
        e.dataTransfer.effectAllowed = 'move';
      });
      container.addEventListener('dragend', function(e){
        if (dragging) dragging.classList.remove('dragging-word');
        dragging = null;
        // Save new order
        try {
          const order = Array.from(container.querySelectorAll('.tok-item')).map(function(el){
            const wEl = el.querySelector('.tok-word');
            return wEl ? String(wEl.getAttribute('data-word') || '') : '';
          }).filter(Boolean);
          localStorage.setItem(key, JSON.stringify(order));
        } catch (e) {}
        // Clean visuals
        Array.from(container.children).forEach(function(ch){ ch.classList.remove('drop-target'); });
      });
      container.addEventListener('dragover', function(e){
        if (!dragging) return;
        e.preventDefault();
        const after = getAfterElement(container, e.clientX, e.clientY);
        if (!after) {
          container.appendChild(dragging);
        } else {
          container.insertBefore(dragging, after);
        }
      });
      // Make tokens draggable
      Array.from(container.querySelectorAll('.tok-item')).forEach(function(el){ el.setAttribute('draggable', 'true'); });

      function getAfterElement(container, x, y) {
        const els = Array.from(container.querySelectorAll('.tok-item:not(.dragging-word)'));
        let closest = {offset: Number.NEGATIVE_INFINITY, element: null};
        els.forEach(function(child){
          const box = child.getBoundingClientRect();
          const offset = y - (box.top + box.height / 2);
          if (offset < 0 && offset > closest.offset) {
            closest = {offset: offset, element: child};
          }
        });
        return closest.element;
      }
    });
  } catch (e) { /* ignore */ }
})();
</script>
</body>
</html>
