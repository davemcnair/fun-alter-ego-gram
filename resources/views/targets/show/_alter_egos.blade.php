{{-- resources/views/targets/show/_alter_egos.blade.php --}}
<div class="card">
    <h3 class="word-matches-header">
        Alter Egos
        <span class="alter-egos-controls">
            <label class="control-label">
                <input type="checkbox" x-model="showOnlyFun"> Only fun ({{ $dto->funAlterEgosCount }})
            </label>
            <label class="control-label">
                <input type="checkbox" x-model="excludeBoring"> Exclude boring ({{ $dto->boringAlterEgosCount }})
            </label>
        </span>
    </h3>

    <div x-show="selectedWords.length > 0" class="word-filter-status">
        Showing <span x-text="filteredPhrasesCount"></span> phrases containing selected words
    </div>

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
                        <li x-show="shouldShowPhrase(phrase)" x-text="phrase.phrase"></li>
                    </template>
                </ul>
            </div>
        </template>
    </div>
</div>

<script>
    // Add to targetApp() data
    Object.assign(window.targetApp.prototype, {
        showOnlyFun: false,
        excludeBoring: false,

        shouldShowPhrase(phrase) {
            // Word filter
            if (!this.isPhraseVisible(phrase.id)) {
                return false;
            }

            // Fun filter
            if (this.showOnlyFun && !phrase.isFun) {
                return false;
            }

            // Boring filter
            if (this.excludeBoring && phrase.hasBoring) {
                return false;
            }

            return true;
        },

        getVisiblePhrasesForPattern(patternId) {
            const pattern = this.patternsFilled.find(p => p.id === patternId);
            if (!pattern) return 0;

            return pattern.alterEgos.filter(p => this.shouldShowPhrase(p)).length;
        }
    });
</script>
