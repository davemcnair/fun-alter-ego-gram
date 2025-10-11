{{-- resources/views/targets/show/_alter_egos.blade.php --}}
<div class="card">
    <h3 class="word-matches-header">
        Alter Egos - showing <span x-text="filteredPhrasesCount"></span>of {{ $dto->alterEgosCount }}
    </h3>
    <h4>
        <span class="alter-egos-controls">
            <label class="control-label">
                <input type="checkbox" x-model="showOnlyFun"> Only fun ({{ $dto->funAlterEgosCount }})
            </label>
            <label class="control-label">
                <input type="checkbox" x-model="excludeBoring"> Exclude boring ({{ $dto->boringAlterEgosCount }})
            </label>
            <label class="control-label">
                <input type="checkbox" x-model="excludeDeferred"> Exclude deferred ({{ $dto->deferredAlterEgosCount }})
            </label>
        </span>
    </h4>

    <div id="alterEgoGroups">
        <template x-for="pattern in patternsFilled" :key="pattern.id">
            <div class="alter-ego-group" x-show="getVisiblePhrasesForPattern(pattern.id) > 0">
                <div>
                    <strong x-text="pattern.template"></strong>
                    <span class="tag" x-text="getVisiblePhrasesForPattern(pattern.id)"></span>
                    <span class="tag" x-text="pattern.elapsed + 's'"></span>
                </div>
                <ul class="alter-ego-list">
                    <template x-for="phrase in pattern.alterEgos" :key="phrase.id">
                        <li x-show="shouldShowPhrase(phrase)"
                            :class="{
                                'phrase-fun': phrase.isFun,
                                'phrase-boring': phrase.hasBoring
                            }"
                            x-text="phrase.phrase"></li>
                    </template>
                </ul>
            </div>
        </template>
    </div>
</div>

