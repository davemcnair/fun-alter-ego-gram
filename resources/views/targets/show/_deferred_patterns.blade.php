<div class="card" id="unsearchedPatternsCard">
    <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
        Deferred Patterns ({{$dto->deferredPatternsCount}})
        <span style="margin-left:auto;"><button id="searchAllBtn" type="button">Search All</button></span>
    </h3>
    @if(count($dto->deferredPatterns) === 0)
        <div class="muted">No deferred patterns.</div>
    @else
        <table id="deferredPatternsTable" style="width:100%; border-collapse: collapse;">
            <thead>
            <tr>
                <th style="text-align:left; padding:8px; background:#f3f4f6;">Pattern</th>
                <th style="text-align:left; padding:8px; background:#f3f4f6;">Action</th>
            </tr>
            </thead>
            <tbody>
            @foreach($dto->deferredPatterns as $p)
                <tr id="unp-row-{{ $p->id }}" data-id="{{ $p->id }}"
                    style="border-bottom:1px solid #e5e7eb;">
                    <td style="padding:8px;">{{ $p->template }}</td>
                    <td style="padding:8px;">
                        <button type="button" id="unp-btn-{{ $p->id }}" data-id="{{ $p->id }}"
                                aria-busy="false" onclick="searchPattern({{ $p->id }})">Search
                        </button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <div id="toast" role="status" aria-live="polite"
         style="display:none; margin-top:8px; font-size:14px; color:#b91c1c;"></div>
</div>
