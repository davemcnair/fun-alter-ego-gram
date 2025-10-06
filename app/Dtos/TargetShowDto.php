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
        public bool       $completed,
        public string     $elapsed,
        public int        $patternsCount,
        public int        $patternsFilledCount,
        public array      $starred,
        public Collection $patternsFilled,
        public int        $deferredPatternsCount,
        public Collection $deferredPatterns,
        public int        $alterEgosCount,
        public int        $funAlterEgosCount,
        public int        $boringAlterEgosCount,
        public int        $matchedSignaturesCount,
        public int        $usedSignaturesCount,
        public int        $matchedWordsCount,
        public int        $usedWordsCount,
        public array      $matchedWords,
        public array      $wordToPhraseMap, // ids
    )
    {
    }

    public static function fromTarget(Target $target): self
    {
        $target->loadMissing([
            'patterns.pattern',
            'signaturedPatterns',
            'alterEgos',
            'tokenSignatures.tokenSignature.token', //todo: need this?
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
        $starred = $target->alterEgos()
            ->where('starred', true)
            ->pluck('phrase')
            ->all();

        $matchedSignaturesCount = $target->tokenSignatures()->count();
        $matchedWordsCount = $target->tokenSignatureWords()->count();
        $usedSignaturesCount = $target->tokenSignatures()->where('usedInPattern', true)->count();
        $usedWordsCount = $target->tokenSignatureWords()->where('usedInPhrase', true)->count();
        $matchedWords = $target->matchingWordsByUseTokenAndType();

        $wordToPhraseMap = self::buildWordToPhraseMap($target);

        return new self(
            targetId: $target->id,
            name: $target->name,
            // todo: search params
            //  includeBoring
            //  includeNearly
            completed: $target->status === TargetStatus::processed->name,
            elapsed: number_format($elapsed, 1),
            patternsCount: $patterns->count(),
            patternsFilledCount: $filledPatterns->count(),
            starred: $starred,
            patternsFilled: $filledPatterns,
            deferredPatternsCount: $deferredPatterns->count(),
            deferredPatterns: $deferredPatterns,
            alterEgosCount: $alterEgos->count(),
            funAlterEgosCount: $alterEgos->filter(fn($ae) => $ae->isFun)->count(),
            boringAlterEgosCount: $alterEgos->filter(fn($ae) => $ae->hasBoring)->count(),
            matchedSignaturesCount: $matchedSignaturesCount,
            usedSignaturesCount: $usedSignaturesCount,
            matchedWordsCount: $matchedWordsCount,
            usedWordsCount: $usedWordsCount,
            matchedWords: $matchedWords,
            wordToPhraseMap: $wordToPhraseMap,
        );
    }

    /**
     * Build a map of token_signature_word_id => [alter_ego_ids]
     * This allows filtering phrases by clicked word
     */
    private static function buildWordToPhraseMap(Target $target): array
    {
        // Get all alter egos with their signature pattern relationships
        $alterEgos = $target->alterEgos()
            ->with([
                'targetSignaturedPattern.targetTokenSignatures.tokenSignature.words'
            ])
            ->get();

        $map = [];

        foreach ($alterEgos as $alterEgo) {
            $wordIds = [];

            // Collect all word IDs that could be in this phrase
            foreach ($alterEgo->targetSignaturedPattern->targetTokenSignatures as $tts) {
                foreach ($tts->tokenSignature->words as $word) {
                    $wordIds[] = $word->id;
                }
            }

            // Map each word to this alter ego
            foreach (array_unique($wordIds) as $wordId) {
                if (!isset($map[$wordId])) {
                    $map[$wordId] = [];
                }
                $map[$wordId][] = $alterEgo->id;
            }
        }

        return $map;
    }
}
