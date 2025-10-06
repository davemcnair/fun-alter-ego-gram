<div class="card">
    <h3 class="word-matches-header">
        Alter Egos
        <span class="alter-egos-controls">
            <label class="control-label">
                <input type="checkbox" id="onlyFunToggle"> Only fun ({{ $dto->funAlterEgosCount }})
            </label>
            <label class="control-label">
                <input type="checkbox" id="excludeBoring"> Exclude boring ({{ $dto->boringAlterEgosCount }})
            </label>
        </span>
    </h3>
    <div id="wordFilterStatus" class="muted word-filter-status"></div>
    <div id="starredSection" class="starred-section">
        <div><strong>Starred</strong> <span class="tag"><span id="starredCount">0</span></span></div>
        <ul id="starredList" class="starred-list"></ul>
    </div>
    <div id="alterEgoGroups">
        @php $hasAny = false; @endphp
        @foreach($dto->patternsFilled as $p)
            @if($p->alterEgosCount > 0)
                @php $hasAny = true; @endphp
                <div class="alter-ego-group">
                    <div>
                        <strong>{{ $p->template }}</strong>
                        <span class="tag">{{ $p->alterEgosCount }}</span>
                        <span class="tag">{{ $p->elapsed }}s</span>
                    </div>
                    <ul class="alter-ego-list">
                        @foreach($p->alterEgos as $phrase)
                            <li x-show="isPhraseVisible({{ $phrase->id }})">
                                {{ $phrase->phrase }}
                            </li>
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
