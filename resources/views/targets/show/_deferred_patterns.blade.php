<div class="card" id="unsearchedPatternsCard">
    <h3 class="word-matches-header">
        Deferred Patterns ({{$dto->deferredPatternsCount}})
        <span class="header-action">
            <button id="searchAllBtn" type="button">Search All</button>
        </span>
    </h3>
    @if(count($dto->deferredPatterns) === 0)
        <div class="muted">No deferred patterns.</div>
    @else
        <table id="deferredPatternsTable" class="words-table">
            <thead>
            <tr>
                <th>Pattern</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @foreach($dto->deferredPatterns as $p)
                <tr id="unp-row-{{ $p->id }}" data-id="{{ $p->id }}">
                    <td>{{ $p->template }}</td>
                    <td>
                        <button type="button" id="unp-btn-{{ $p->id }}" data-id="{{ $p->id }}" aria-busy="false" onclick="searchPattern({{ $p->id }})">Search</button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <div id="toast" role="status" aria-live="polite" class="toast"></div>
</div>
