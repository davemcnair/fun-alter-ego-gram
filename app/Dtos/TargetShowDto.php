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
        public int $targetId,
        public string $name,
        public string $status,
        public bool $completed,
        public string $elapsed,
        public int $patternsCount,
        public int $patternsFilledCount,
        public array $starred,
        public Collection $patternsFilled,
        public int $deferredPatternsCount,
        public Collection $deferredPatterns,
        public int $alterEgosCount,
        public int $starredAlterEgosCount,
        public int $funAlterEgosCount,
        public int $boringAlterEgosCount,
        public int $deferredAlterEgosCount,
        public int $matchedSignaturesCount,
        public int $usedSignaturesCount,
        public int $matchedWordsCount,
        public int $usedWordsCount,
        public array $matchedWords,
        public array $wordToPhraseMap,
        public array $wordUsageCounts,
        public bool $hasUncommitted,
    ) {}

    public static function fromTarget(Target $target): self
    {
        $target->loadMissing([
            'patterns.pattern',
            'signaturedPatterns',
            'alterEgos',
            'tokenSignatures.tokenSignature.token',
        ]);

        $patterns = $target->patterns
            ->map(fn (TargetPattern $p) => TargetPatternShowDto::fromTargetPattern($p));
        $livePatterns = $patterns->filter(fn ($p) => $p->status !== TargetPatternStatus::DEFERRED->value);
        $filledPatterns = $livePatterns->filter(fn ($p) => $p->status === TargetPatternStatus::FILLED->value);

        $elapsed = 0;
        foreach ($filledPatterns as $pattern) {
            $elapsed += $pattern->elapsed;
        }

        $deferredPatterns = $patterns->filter(fn ($p) => $p->status === TargetPatternStatus::DEFERRED->value);
        $alterEgos = $target->alterEgos;
        $starred = $alterEgos->filter(fn ($ae) => $ae->starred);

        $matchedSignaturesCount = $target->tokenSignatures()->count();
        $matchedWordsCount = $target->tokenSignatureWords()->count();
        $usedSignaturesCount = $target->tokenSignatures()->where('usedInPattern', true)->count();
        $usedWordsCount = $target->tokenSignatureWords()->where('usedInPhrase', true)->count();
        $matchedWords = self::matchedWordsByTokenAndType($target);

        [$wordToPhraseMap, $wordUsageCounts] = self::buildWordMappings($target);

        $hasUncommitted = $target->tokenSignatureWords()
            ->whereHas('tokenSignatureWord', fn ($q) => $q->whereNull('committed_at'))
            ->exists();

        return new self(
            targetId: $target->id,
            name: $target->name,
            status: $target->status->name,
            completed: $target->status === TargetStatus::processed,
            elapsed: number_format($elapsed, 1),
            patternsCount: $patterns->count(),
            patternsFilledCount: $filledPatterns->count(),
            starred: $starred->pluck('phrase')->toArray(),
            patternsFilled: $filledPatterns,
            deferredPatternsCount: $deferredPatterns->count(),
            deferredPatterns: $deferredPatterns,
            alterEgosCount: $alterEgos->count(),
            starredAlterEgosCount: $starred->count(),
            funAlterEgosCount: $alterEgos->filter(fn ($ae) => $ae->isFun)->count(),
            boringAlterEgosCount: $alterEgos->filter(fn ($ae) => $ae->hasBoring)->count(),
            deferredAlterEgosCount: $alterEgos->filter(fn ($ae) => $ae->hasDeferred)->count(),
            matchedSignaturesCount: $matchedSignaturesCount,
            usedSignaturesCount: $usedSignaturesCount,
            matchedWordsCount: $matchedWordsCount,
            usedWordsCount: $usedWordsCount,
            matchedWords: $matchedWords,
            wordToPhraseMap: $wordToPhraseMap,
            wordUsageCounts: $wordUsageCounts,
            hasUncommitted: $hasUncommitted,
        );
    }

    /**
     * @return array<string, array<string, list<WordDto>>>
     */
    private static function matchedWordsByTokenAndType(Target $target): array
    {
        $words = DB::table('target_token_signature_words as ttsw')
            ->join('token_signature_words as tsw', 'tsw.id', '=', 'ttsw.token_signature_word_id')
            ->join('token_signatures as ts', 'ts.id', '=', 'tsw.token_signature_id')
            ->join('tokens as t', 't.id', '=', 'ts.token_id')
            ->where('ttsw.target_id', $target->id)
            ->select([
                'tsw.id as word_id',
                'tsw.word',
                'tsw.list_type',
                'tsw.is_deferred',
                DB::raw("CASE WHEN tsw.list_type = 'ok' AND t.name IN ('forename', 'surname') THEN 1 ELSE 0 END as is_promotable"),
                't.name as token_name',
                'ttsw.usedInPhrase',
            ])
            ->get();

        $usageCounts = self::wordUsageCounts($target);

        $out = [];
        foreach ($words as $row) {
            $token = $row->token_name;
            $list = $row->list_type;
            if (! isset($out[$token][$list])) {
                $out[$token][$list] = [];
            }
            $out[$token][$list][] = new WordDto(
                tokenType: $token,
                word: $row->word,
                listType: $list,
                isPromotable: (bool) ($row->is_promotable ?? false),
                id: (string) $row->word_id,
                deferred: (bool) $row->is_deferred,
                used: (bool) $row->usedInPhrase,
                usageCount: (int) ($usageCounts[$row->word_id] ?? 0),
            );
        }

        foreach ($out as &$lists) {
            foreach ($lists as &$items) {
                usort($items, fn ($a, $b) => strcasecmp($a->word, $b->word));
            }
        }

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private static function wordUsageCounts(Target $target): array
    {
        $results = DB::table('alter_egos as ae')
            ->join('target_signatured_patterns as tsp', 'tsp.id', '=', 'ae.target_signatured_pattern_id')
            ->join('target_patterns as tp', 'tp.id', '=', 'tsp.target_pattern_id')
            ->join('target_signatured_pattern_target_token_signature as tsptts', 'tsptts.target_signatured_pattern_id', '=', 'tsp.id')
            ->join('target_token_signatures as tts', 'tts.id', '=', 'tsptts.target_token_signature_id')
            ->join('token_signature_words as tsw', 'tsw.token_signature_id', '=', 'tts.token_signature_id')
            ->where('tp.target_id', $target->id)
            ->select([
                'tsw.id as word_id',
                DB::raw('COUNT(DISTINCT ae.id) as usage_count'),
            ])
            ->groupBy('tsw.id')
            ->get();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row->word_id] = (int) $row->usage_count;
        }

        return $counts;
    }

    /**
     * @return array{0: array<int, list<int>>, 1: array<int, int>}
     */
    private static function buildWordMappings(Target $target): array
    {
        $results = DB::table('alter_egos as ae')
            ->join('target_signatured_patterns as tsp', 'tsp.id', '=', 'ae.target_signatured_pattern_id')
            ->join('target_patterns as tp', 'tp.id', '=', 'tsp.target_pattern_id')
            ->join('target_signatured_pattern_target_token_signature as tsptts', 'tsptts.target_signatured_pattern_id', '=', 'tsp.id')
            ->join('target_token_signatures as tts', 'tts.id', '=', 'tsptts.target_token_signature_id')
            ->join('token_signature_words as tsw', 'tsw.token_signature_id', '=', 'tts.token_signature_id')
            ->where('tp.target_id', $target->id)
            ->select([
                'tsw.id as word_id',
                'ae.id as alter_ego_id',
            ])
            ->get();

        $wordToPhraseMap = [];
        $wordUsageCounts = [];

        foreach ($results as $row) {
            $wordId = $row->word_id;
            $alterEgoId = $row->alter_ego_id;

            if (! isset($wordToPhraseMap[$wordId])) {
                $wordToPhraseMap[$wordId] = [];
                $wordUsageCounts[$wordId] = 0;
            }

            if (! in_array($alterEgoId, $wordToPhraseMap[$wordId], true)) {
                $wordToPhraseMap[$wordId][] = $alterEgoId;
            }
            $wordUsageCounts[$wordId]++;
        }

        return [$wordToPhraseMap, $wordUsageCounts];
    }
}
