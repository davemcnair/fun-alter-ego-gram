<div class="card">
    <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
        Alter Egos
        <span
            style="margin-left:auto; display:flex; align-items:center; gap:12px; font-weight:normal; font-size:14px;">
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" id="onlyStarredToggle"> Favourites only
            </label>
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" id="onlyFunToggle"> Only fun
            </label>
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" id="showElapsedToggle"> Show elapsed
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
        @foreach($dto->patternsFilled as $p)
            @if($p->alterEgosCount > 0)
                @php $hasAny = true; @endphp
                <div style="margin-bottom:10px;">
                    <div><strong>{{ $p->template }}</strong> <span class="tag">{{ $p->alterEgosCount }}</span></div>
                    <ul style="margin-top:6px; max-height:240px; overflow:auto; border:1px solid #eee; border-radius:6px; padding:4px 8px; list-style:none; padding-left:0;">
                        @foreach($p->alterEgoPhrases as $phrase)
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
