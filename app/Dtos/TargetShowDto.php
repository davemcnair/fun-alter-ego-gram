<?php

namespace App\Dtos;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Models\Target;
use App\Models\TargetPattern;
use Illuminate\Support\Collection;
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
        $target->loadMissing([
            'patterns.pattern',
            'signaturedPatterns',
            'alterEgos',
            'tokenSignatures.tokenSignature.token',
            'tokenSignatures.tokenSignature.words',
            'tokenSignatureWords.tokenSignatureWord'
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
     */
    private static function buildWordMappings(Target $target): array
    {
        $alterEgos = $target->alterEgos()
            ->with([
                'targetSignaturedPattern.targetTokenSignatures.tokenSignature.words'
            ])
            ->get();

        $wordToPhraseMap = [];
        $wordUsageCounts = [];

        foreach ($alterEgos as $alterEgo) {
            $wordIds = [];

            foreach ($alterEgo->targetSignaturedPattern->targetTokenSignatures as $tts) {
                foreach ($tts->tokenSignature->words as $word) {
                    $wordIds[] = $word->id;
                }
            }

            foreach (array_unique($wordIds) as $wordId) {
                if (!isset($wordToPhraseMap[$wordId])) {
                    $wordToPhraseMap[$wordId] = [];
                    $wordUsageCounts[$wordId] = 0;
                }
                $wordToPhraseMap[$wordId][] = $alterEgo->id;
                $wordUsageCounts[$wordId]++;
            }
        }

        return [$wordToPhraseMap, $wordUsageCounts];
    }
}
