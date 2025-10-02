@php use App\Enums\TargetStatus;use App\Models\Token; @endphp
    <!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Search: {{ $dto->name }}</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f7fafc;
            color: #111827;
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px;
        }

        h1 {
            font-weight: 600;
            font-size: 24px;
            margin: 8px 0 16px;
        }

        .card {
            background: #fff;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .06);
            margin-bottom: 16px;
        }

        button {
            background: #2563eb;
            color: white;
            border: 0;
            border-radius: 6px;
            padding: 10px 14px;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .muted {
            color: #6b7280;
        }

        .tag {
            background: #eef2ff;
            color: #3730a3;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 12px;
        }

        ul {
            margin: 0;
            padding-left: 18px;
        }

        li {
            margin: 4px 0;
        }

        a.link {
            color: #2563eb;
            text-decoration: none;
        }

        .columns {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 900px) {
            .columns {
                grid-template-columns: 1fr 1fr;
            }
        }

        .highlight-fun {
            background: #fff3cd;
            color: #92400e;
            padding: 0 3px;
            border-radius: 3px;
        }

        .highlight-match {
            background: #dcfce7;
            color: #065f46;
            padding: 0 3px;
            border-radius: 3px;
            cursor: pointer;
        }

        .highlight-active {
            outline: 2px solid #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2) inset;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfeff;
            color: #0e7490;
            border: 1px solid #a5f3fc;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 12px;
        }

        .filter-pill button {
            background: none;
            color: #0e7490;
            border: 0;
            cursor: pointer;
            padding: 0;
        }

        .star-btn {
            background: none;
            border: 0;
            font-size: 16px;
            cursor: pointer;
            color: #9ca3af;
            padding: 0 4px;
        }

        .star-btn.starred {
            color: #f59e0b;
        }

        .dragging-word {
            opacity: 0.6;
        }

        .drop-target {
            outline: 2px dashed #93c5fd;
        }

        .ph-block {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .ph-part {
            display: inline-block;
            padding: 0 2px;
            cursor: grab;
        }

        .ph-part.dragging-word {
            opacity: 0.7;
        }

        .ph-sep {
            opacity: 0.6;
            user-select: none;
        }
    </style>
</head>
<body>
<nav style="background:#111827; color:#fff; padding:8px 12px;">
    <a href="{{ route('targets.index') }}"
       style="color:#fff; margin-right:10px; text-decoration:none;"><strong>Targets</strong></a>
    <a href="{{ route('patterns.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Patterns</a>
    <a href="{{ route('words.index') }}" style="color:#fff; margin-right:10px; text-decoration:none;">Words</a>
</nav>
<div class="container">
    <h1>Alter Egos for: {{ $dto->name }}</h1>

    <div class="card">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items:center;">
            <div>

                <div id="patternsRow" style="margin-top:6px;">Patterns searched: <strong
                        id="patternsSearched">{{ $dto->patternsFilledCount }}</strong> / <strong id="patternsTotal">{{ $dto->patternsCount }}</strong><span
                        id="patternsElapsed" class="tag" style="margin-left:6px; display:none;"></span></div>
                <div style="margin-top:6px;">Alter egos found: <strong id="alterEgosFound">{{ $dto->alterEgosCount }}</strong>
            </div>
        </div>
    </div>

    <div class="columns">
        @include('targets.show._alter_egos')

        <div>
            @include('targets.show._add_word_form')
            <div class="card">
                <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">Word Matches
                    ({{$dto->matchedWordsCount}})
                    <label
                        style="margin-left:auto; font-weight:normal; display:flex; align-items:center; gap:6px; font-size:14px;">
                        <input type="checkbox" id="onlyUsedToggle" checked> Only used
                    </label>
                </h3>
                <div id="tokenMatchesContainer">
                    @if(empty($dto->matchedWords))
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
                            @foreach($dto->matchedWords as $token => $byList)
                                @foreach($byList as $listType => $items)
                                    @php $count = count($items); $sample = array_slice($items, 0, 5); $rowId = md5($token.'|'.$listType); @endphp
                                    <tr id="row-{{ $rowId }}" data-rowid="{{ $rowId }}" data-token="{{ $token }}"
                                        data-list="{{ $listType }}" data-total="{{ $count }}"
                                        style="border-bottom:1px solid #e5e7eb;">
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
                                                        <span class="tok-word" data-token="{{ $token }}"
                                                              data-word="{{ $w }}"
                                                              style="display:inline-block; margin-right:6px; cursor:pointer; text-decoration:underline;"
                                                              onclick="promoteOkWord({{ $wid }}, '{{ addslashes($w) }}')"
                                                              title="Promote to fun">{{ $w }}</span>
                                                        <button type="button" class="link"
                                                                style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;"
                                                                onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')"></button>
                                                    @elseif(in_array($token, ['forename','surname']))
                                                        <span class="tok-word" data-token="{{ $token }}"
                                                              data-word="{{ $w }}"
                                                              style="display:inline-block; margin-right:6px; cursor:pointer; color:#2563eb; text-decoration:underline;"
                                                              onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')">{{ $w }}</span>
                                                    @else
                                                        <span class="tok-word" data-token="{{ $token }}"
                                                              data-word="{{ $w }}"
                                                              style="display:inline-block; margin-right:6px;">{{ $w }}</span>
                                                    @endif
                                                @endforeach
                                                @if($count > count($sample))
                                                    <button type="button" class="link"
                                                            style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;"
                                                            onclick="toggleWords('{{ $rowId }}', true)">show all
                                                        ({{ $count }})
                                                    </button>
                                                @endif
                                            </div>
                                            <div id="all-{{ $rowId }}"
                                                 style="display:block; max-height:160px; overflow:auto;">
                                                @foreach($items as $it)
                                                    @php $wid = (int)($it['id'] ?? 0); $w = (string)($it['word'] ?? ''); @endphp
                                                    @if(in_array($token, ['forename','surname']) && $listType === 'ok')
                                                        <span class="tok-word" data-token="{{ $token }}"
                                                              data-word="{{ $w }}"
                                                              style="display:inline-block; margin-right:6px; cursor:pointer; text-decoration:underline;"
                                                              onclick="promoteOkWord({{ $wid }}, '{{ addslashes($w) }}')"
                                                              title="Promote to fun">{{ $w }}</span>
                                                        <button type="button" class="link"
                                                                style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;"
                                                                onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')"></button>
                                                    @elseif(in_array($token, ['forename','surname']))
                                                        <span class="tok-word" data-token="{{ $token }}"
                                                              data-word="{{ $w }}"
                                                              style="display:inline-block; margin-right:6px; cursor:pointer; color:#2563eb; text-decoration:underline;"
                                                              onclick="window.setWordFilter('{{ addslashes($w) }}','{{ $token }}')">{{ $w }}</span>
                                                    @else
                                                        <span class="tok-word" data-token="{{ $token }}"
                                                              data-word="{{ $w }}"
                                                              style="display:inline-block; margin-right:6px;">{{ $w }}</span>
                                                    @endif
                                                @endforeach
                                                <div>
                                                    <button type="button" class="link"
                                                            style="border:0;background:none;color:#2563eb;cursor:pointer;padding:0;"
                                                            onclick="toggleWords('{{ $rowId }}', false)">show less
                                                    </button>
                                                </div>
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

            @include('targets.show._deferred_patterns')
        </div>
    </div>
</div>

</body>
</html>
