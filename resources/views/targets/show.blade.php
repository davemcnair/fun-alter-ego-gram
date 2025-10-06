{{-- resources/views/targets/show.blade.php --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Search: {{ $dto->name }}</title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
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

        nav {
            background: #111827;
            color: #fff;
            padding: 8px 12px;
        }

        nav a {
            color: #fff;
            margin-right: 10px;
            text-decoration: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            align-items: center;
        }

        .patterns-row {
            margin-top: 6px;
        }

        .word-matches-header {
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .word-matches-toggle {
            margin-left: auto;
            font-weight: normal;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }

        .words-table {
            width: 100%;
            border-collapse: collapse;
        }

        .words-table th {
            text-align: left;
            padding: 8px;
            background: #f3f4f6;
        }

        .words-table td {
            padding: 8px;
        }

        .words-table tr {
            border-bottom: 1px solid #e5e7eb;
        }

        .word-samples {
            display: block;
            max-height: 160px;
            overflow: auto;
        }

        .tok-word {
            display: inline-block;
            margin-right: 6px;
        }

        .tok-word-clickable {
            cursor: pointer;
            text-decoration: underline;
        }

        .tok-word-link {
            cursor: pointer;
            color: #2563eb;
            text-decoration: underline;
        }

        .btn-link {
            border: 0;
            background: none;
            color: #2563eb;
            cursor: pointer;
            padding: 0;
        }

        .alter-egos-controls {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: normal;
            font-size: 14px;
        }

        .control-label {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .word-filter-status {
            margin: 4px 0 8px 0;
            font-size: 14px;
        }

        .starred-section {
            margin-bottom: 10px;
            display: none;
        }

        .starred-list {
            margin-top: 6px;
        }

        .alter-ego-group {
            margin-bottom: 10px;
        }

        .alter-ego-list {
            margin-top: 6px;
            max-height: 240px;
            overflow: auto;
            border: 1px solid #eee;
            border-radius: 6px;
            padding: 4px 8px;
            list-style: none;
            padding-left: 0;
        }

        .add-word-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 8px;
            align-items: end;
        }

        .form-field {
        }

        .form-label {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .form-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }

        .form-errors {
            margin-top: 6px;
        }

        .header-action {
            margin-left: auto;
        }

        .toast {
            display: none;
            margin-top: 8px;
            font-size: 14px;
            color: #b91c1c;
        }

        .patterns-elapsed {
            margin-left: 6px;
            display: none;
        }

        .word-filter-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
        }

        .filter-select {
            padding: 6px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .word-used {
            background: #d1fae5;
        }

        .word-deferred {
            background: #fef3c7;
        }

        .word-fun {
            color: #065f46;
            font-weight: 600;
        }

        .word-boring {
            color: #991b1b;
        }

        .word-clickable {
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .word-clickable:hover {
            background: #e0e7ff;
        }

        .word-selected {
            background: #818cf8;
            color: white;
        }

        .clear-filters-btn {
            background: #ef4444;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }

        .filter-status {
            font-size: 14px;
            padding: 8px;
            background: #eff6ff;
            border-radius: 6px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body x-data="targetApp()" x-init="init()">
<nav>
    <a href="{{ route('targets.index') }}"><strong>Targets</strong></a>
    <a href="{{ route('patterns.index') }}">Patterns</a>
    <a href="{{ route('words.index') }}">Words</a>
</nav>

<div class="container">
    <h1>Alter Egos for: {{ $dto->name }}</h1>

    <div class="card">
        <div class="stats-grid">
            <div>
                <div class="patterns-row">
                    Patterns searched: <strong>{{ $dto->patternsFilledCount }}</strong> / <strong>{{ $dto->patternsCount }}</strong>
                    <span class="tag">{{ $dto->elapsed }}s</span>
                </div>
                <div class="patterns-row">
                    Alter egos found: <strong>{{ $dto->alterEgosCount }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="columns">
        @include('targets.show._alter_egos')

        <div>
            @include('targets.show._add_word_form')

            <div class="card">
                <h3 class="word-matches-header">
                    Word Matches (<span x-text="filteredWordsCount"></span>)
                </h3>

                {{-- Filter Controls --}}
                <div class="word-filter-controls">
                    <div class="filter-group">
                        <label class="filter-label">Show</label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" x-model="showOnlyUsed">
                            Only used (<span x-text="usedWordsCount"></span>)
                        </label>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Token</label>
                        <select x-model="filterToken" class="filter-select">
                            <option value="">All tokens</option>
                            <template x-for="token in availableTokens" :key="token">
                                <option :value="token" x-text="token"></option>
                            </template>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">List Type</label>
                        <select x-model="filterListType" class="filter-select">
                            <option value="">All types</option>
                            <option value="fun">Fun</option>
                            <option value="ok">OK</option>
                            <option value="boring">Boring</option>
                        </select>
                    </div>

                    <div class="filter-group" style="justify-content: flex-end;">
                        <button @click="clearFilters()" class="clear-filters-btn">
                            Clear Filters
                        </button>
                    </div>
                </div>

                {{-- Filter Status --}}
                <div x-show="selectedWords.length > 0" class="filter-status">
                    <strong>Filtering phrases by:</strong>
                    <template x-for="wordId in selectedWords" :key="wordId">
                        <span class="filter-pill">
                            <span x-text="getWordText(wordId)"></span>
                            <button @click="deselectWord(wordId)">×</button>
                        </span>
                    </template>
                    <span x-text="'(' + filteredPhrasesCount + ' phrases)'"></span>
                </div>

                {{-- Words Table --}}
                <div id="tokenMatchesContainer">
                    <table class="words-table">
                        <thead>
                        <tr>
                            <th>Token</th>
                            <th>List</th>
                            <th>Count</th>
                            <th>Sample</th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="(byList, token) in matchedWords" :key="token">
                            <template x-for="(words, listType) in byList" :key="token + '-' + listType">
                                <tr x-show="shouldShowRow(token, listType, words)">
                                    <td x-text="token"></td>
                                    <td><span class="tag" x-text="listType"></span></td>
                                    <td x-text="getVisibleWordsCount(words)"></td>
                                    <td class="muted">
                                        <div class="word-samples">
                                            <template x-for="word in words" :key="word.id">
                                                <span
                                                    x-show="shouldShowWord(word)"
                                                    class="word-clickable"
                                                    :class="{
                                                        'word-used': word.used,
                                                        'word-deferred': word.deferred,
                                                        'word-fun': word.listType === 'fun',
                                                        'word-boring': word.listType === 'boring',
                                                        'word-selected': isWordSelected(word.id)
                                                    }"
                                                    @click="toggleWordSelection(word.id)"
                                                    x-text="word.word + (word.usageCount > 0 ? ' (' + word.usageCount + ')' : '')">
                                                </span>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </template>
                        </tbody>
                    </table>
                </div>
            </div>

            @include('targets.show._deferred_patterns')
        </div>
    </div>
</div>

<script>
    function targetApp() {
        return {
            // Data
            matchedWords: @js($dto->matchedWords),
            wordToPhraseMap: @js($dto->wordToPhraseMap),
            wordUsageCounts: @js($dto->wordUsageCounts),
            patternsFilled: @js($dto->patternsFilled->toArray()),

            // Filters
            showOnlyUsed: true,
            filterToken: '',
            filterListType: '',
            selectedWords: [],

            // Computed
            availableTokens: [],
            usedWordsCount: {{ $dto->usedWordsCount }},
            totalWordsCount: {{ $dto->matchedWordsCount }},

            init() {
                // Extract unique tokens
                this.availableTokens = Object.keys(this.matchedWords);

                // Load saved preferences
                const saved = localStorage.getItem('wordFilters');
                if (saved) {
                    const prefs = JSON.parse(saved);
                    this.showOnlyUsed = prefs.showOnlyUsed ?? true;
                    this.filterToken = prefs.filterToken ?? '';
                    this.filterListType = prefs.filterListType ?? '';
                }
            },

            // Word filtering
            shouldShowWord(word) {
                if (this.showOnlyUsed && !word.used) {
                    return false;
                }
                return true;
            },

            shouldShowRow(token, listType, words) {
                if (this.filterToken && token !== this.filterToken) {
                    return false;
                }
                if (this.filterListType && listType !== this.filterListType) {
                    return false;
                }
                // Check if any words in this row would be visible
                return words.some(w => this.shouldShowWord(w));
            },

            getVisibleWordsCount(words) {
                return words.filter(w => this.shouldShowWord(w)).length;
            },

            // Word selection for phrase filtering
            toggleWordSelection(wordId) {
                const idx = this.selectedWords.indexOf(wordId);
                if (idx > -1) {
                    this.selectedWords.splice(idx, 1);
                } else {
                    this.selectedWords.push(wordId);
                }
            },

            isWordSelected(wordId) {
                return this.selectedWords.includes(wordId);
            },

            deselectWord(wordId) {
                const idx = this.selectedWords.indexOf(wordId);
                if (idx > -1) {
                    this.selectedWords.splice(idx, 1);
                }
            },

            getWordText(wordId) {
                // Find word text from matchedWords
                for (const token in this.matchedWords) {
                    for (const listType in this.matchedWords[token]) {
                        const word = this.matchedWords[token][listType].find(w => w.id === wordId);
                        if (word) return word.word;
                    }
                }
                return '';
            },

            // Phrase filtering
            isPhraseVisible(phraseId) {
                if (this.selectedWords.length === 0) return true;

                // Show phrase if it contains ANY selected word
                return this.selectedWords.some(wordId => {
                    return (this.wordToPhraseMap[wordId] || []).includes(phraseId);
                });
            },

            clearFilters() {
                this.showOnlyUsed = false;
                this.filterToken = '';
                this.filterListType = '';
                this.selectedWords = [];
                this.savePreferences();
            },

            savePreferences() {
                localStorage.setItem('wordFilters', JSON.stringify({
                    showOnlyUsed: this.showOnlyUsed,
                    filterToken: this.filterToken,
                    filterListType: this.filterListType
                }));
            },

            // Computed properties
            get filteredWordsCount() {
                let count = 0;
                for (const token in this.matchedWords) {
                    for (const listType in this.matchedWords[token]) {
                        count += this.getVisibleWordsCount(this.matchedWords[token][listType]);
                    }
                }
                return count;
            },

            get filteredPhrasesCount() {
                if (this.selectedWords.length === 0) return 0;

                let count = 0;
                this.patternsFilled.forEach(pattern => {
                    pattern.alterEgos.forEach(phrase => {
                        if (this.isPhraseVisible(phrase.id)) {
                            count++;
                        }
                    });
                });
                return count;
            }
        }
    }

    // Watch for changes and save preferences
    document.addEventListener('alpine:init', () => {
        Alpine.effect(() => {
            const app = Alpine.$data(document.body);
            if (app) {
                app.$watch('showOnlyUsed', () => app.savePreferences());
                app.$watch('filterToken', () => app.savePreferences());
                app.$watch('filterListType', () => app.savePreferences());
            }
        });
    });
</script>

</body>
</html>
