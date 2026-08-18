<?php

namespace App\Dtos;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Models\Target;
use App\Models\TargetPattern;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;

class TargetShowDto extends Data
{
    public function __construct(
        public int        $targetId,
        public string     $name,
        public bool       $includeBoring,
        public bool       $includeNearly,
        public bool       $includeDeferred,
        public bool       $includeUsed,
        public bool       $onlyStarred,
        public bool       $completed,
        public string     $elapsed,
        public int        $patternsCount,
        public int        $patternsFilledCount,
        public array      $starred,
        public Collection $patternsFilled,
        public int        $deferredPatternsCount,
        public Collection $deferredPatterns,
        public int        $alterEgosCount,
        public int        $starredAlterEgosCount,
        public int        $funAlterEgosCount,
        public int        $boringAlterEgosCount,
        public int        $deferredAlterEgosCount,
        public int        $matchedSignaturesCount,
        public int        $usedSignaturesCount,
        public int        $matchedWordsCount,
        public int        $usedWordsCount,
        public array      $matchedWords,
        public array      $wordToPhraseMap,
        public array      $wordUsageCounts, // NEW: word_id => phrase count
    )
    {
    }

    public static function fromTarget(Target $target): self
    {
        // Only load essential relationships - avoid loading all words to prevent memory exhaustion
        // For large targets (e.g., 701+ token signatures), loading all words causes memory issues
        $target->loadMissing([
            'patterns.pattern',
            'signaturedPatterns',
            'alterEgos',
            'tokenSignatures.tokenSignature.token',
            // Removed: 'tokenSignatures.tokenSignature.words' - too memory intensive
            // Removed: 'tokenSignatureWords.tokenSignatureWord' - will load via query when needed
        ]);

        $patterns = $target->patterns
            ->map(fn(TargetPattern $p) => TargetPatternShowDto::fromTargetPattern($p));
        $livePatterns = $patterns->filter(fn($p) => $p->status !== TargetPatternStatus::DEFERRED->value);
        $filledPatterns = $livePatterns->filter(fn($p) => $p->status === TargetPatternStatus::FILLED->value);

        $elapsed = 0;
        foreach($filledPatterns as $pattern){
            $elapsed += $pattern->elapsed;
        }

        $deferredPatterns = $patterns->filter(fn($p) => $p->status === TargetPatternStatus::DEFERRED->value);
        $alterEgos = $target->alterEgos;
        $starred = $alterEgos->filter(fn($ae) => $ae->starred);

        $matchedSignaturesCount = $target->tokenSignatures()->count();
        $matchedWordsCount = $target->tokenSignatureWords()->count();
        $usedSignaturesCount = $target->tokenSignatures()->where('usedInPattern', true)->count();
        $usedWordsCount = $target->tokenSignatureWords()->where('usedInPhrase', true)->count();
        $matchedWords = $target->matchingWordsByUseTokenAndType();

        // Build word-to-phrase mapping
        [$wordToPhraseMap, $wordUsageCounts] = self::buildWordMappings($target);

        return new self(
            targetId: $target->id,
            name: $target->name,
            includeBoring: true,
            includeNearly: false,
            includeDeferred: true,
            includeUsed: true,
            onlyStarred: false,
            completed: $target->status === TargetStatus::processed->name,
            elapsed: number_format($elapsed,1),
            patternsCount: $patterns->count(),
            patternsFilledCount: $filledPatterns->count(),
            starred: $starred->pluck('phrase')->toArray(),
            patternsFilled: $filledPatterns,
            deferredPatternsCount: $deferredPatterns->count(),
            deferredPatterns: $deferredPatterns,
            alterEgosCount: $alterEgos->count(),
            starredAlterEgosCount: $starred->count(),
            funAlterEgosCount: $alterEgos->filter(fn($ae) => $ae->isFun)->count(),
            boringAlterEgosCount: $alterEgos->filter(fn($ae) => $ae->hasBoring)->count(),
            deferredAlterEgosCount: $alterEgos->filter(fn($ae) => $ae->hasDeferred)->count(),
            matchedSignaturesCount: $matchedSignaturesCount,
            usedSignaturesCount: $usedSignaturesCount,
            matchedWordsCount: $matchedWordsCount,
            usedWordsCount: $usedWordsCount,
            matchedWords: $matchedWords,
            wordToPhraseMap: $wordToPhraseMap,
            wordUsageCounts: $wordUsageCounts,
        );
    }

    /**
     * Build mappings: word_id => [phrase_ids] and word_id => count
     * Uses SQL queries to avoid loading all models into memory
     */
    private static function buildWordMappings(Target $target): array
    {
        // Use SQL to build word-to-phrase mappings without loading all models
        // This is memory-efficient for targets with many alter egos and words
        $results = DB::table('alter_egos as ae')
            ->join('target_signatured_patterns as tsp', 'tsp.id', '=', 'ae.target_signatured_pattern_id')
            ->join('target_patterns as tp', 'tp.id', '=', 'tsp.target_pattern_id')
            ->join('target_signatured_pattern_target_token_signature as tsptts', 'tsptts.target_signatured_pattern_id', '=', 'tsp.id')
            ->join('target_token_signatures as tts', 'tts.id', '=', 'tsptts.target_token_signature_id')
            ->join('token_signature_words as tsw', 'tsw.token_signature_id', '=', 'tts.token_signature_id')
            ->where('tp.target_id', $target->id)
            ->select([
                'tsw.id as word_id',
                'ae.id as alter_ego_id'
            ])
            ->get();

        $wordToPhraseMap = [];
        $wordUsageCounts = [];

        foreach ($results as $row) {
            $wordId = $row->word_id;
            $alterEgoId = $row->alter_ego_id;
            
            if (!isset($wordToPhraseMap[$wordId])) {
                $wordToPhraseMap[$wordId] = [];
                $wordUsageCounts[$wordId] = 0;
            }
            
            // Only add if not already in the list (avoid duplicates)
            if (!in_array($alterEgoId, $wordToPhraseMap[$wordId])) {
                $wordToPhraseMap[$wordId][] = $alterEgoId;
            }
            $wordUsageCounts[$wordId]++;
        }

        return [$wordToPhraseMap, $wordUsageCounts];
    }
}
