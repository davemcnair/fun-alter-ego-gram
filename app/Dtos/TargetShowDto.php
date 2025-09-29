<?php

namespace App\Dtos;

use App\Enums\TargetPatternStatus;
use App\Models\Target;
use App\Models\TargetPattern;
use Spatie\LaravelData\Data;

class TargetShowDto extends Data
{
    public function __construct(
        public int $targetId,
        public string $name,
        public int $patternsCount,
        public int $patternsFilledCount,
        public array $patternsFilled,
        public int $deferredPatternsCount,
        public array $deferredPatterns,
        public int $alterEgosCount,
        public array $starred,
        public int $matchedWordsCount,
        public array $matchedWords,
    ) {
    }

    /**
     * Build the DTO from a Target model, performing all calculations needed by the view.
     */
    public static function fromTarget(Target $target): self
    {
        // Eager load relations used for counting to avoid N+1 in view
        $target->loadMissing(['patterns.pattern', 'signatureIndexedPatterns', 'alterEgos', 'tokenSignatureWords']);

        $patterns = $target->patterns
            ->map(fn (TargetPattern $p) => TargetPatternShowDto::fromTargetPattern($p));
        $livePatterns  = $patterns->filter(fn($p)=>$p->status->isLive());
        $filledPatterns = $livePatterns->filter(fn($p)=>$p->status->filled());
        $deferredPatterns =  $patterns->filter(fn($p)=>$p->status->isDeferred());

        $alterEgosCount = $target->alterEgos()->count();
        $starred = $target->alterEgos()
            ->where('starred', true)
            ->pluck('phrase')
            ->all();

        $matchedWordsCount = $target->tokenSignatureWords()->count();
        $matchedWords = $target->matchingWordsByUseTokenAndType();

        return new self(
            targetId: $target->id,
            name: $target->name,
            patternsCount: $patterns->count(),
            patternsFilledCount: $filledPatterns->count(),
            patternsFilled: $filledPatterns,
            deferredPatternsCount: $deferredPatterns->count(),
            deferredPatterns: $deferredPatterns,
            alterEgosCount: $alterEgosCount,
            starred: $starred,
            matchedWordsCount: $matchedWordsCount,
            matchedWords: $matchedWords,
        );
    }

}
