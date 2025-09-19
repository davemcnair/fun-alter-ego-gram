<?php

namespace App\Services;

use App\Dtos\SignatureDto;
use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetTokenSignatureWord;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class WordMatchService
{
    use HelpsMatchWords;

    /**
     * Add a word for a token/list into TokenSignature/TokenSignatureWord tables.
     * Mirrors ImportWordLists behavior for existing/new signatures and deferral rules.
     *
     * Returns the TokenSignatureWord created.
     */
    public function addTokenWord(
        string $tokenName,
        string $word,
        string $listType,
        ?DateTime $committed_at = null
    ): TokenSignatureWord
    {
        $token = Token::where('name', $tokenName)->first();
        $signatureDto = SignatureDto::fromWord($word);
        // if word has anagram, its signature will be found, otherwise created
        $signature = Signature::firstOrCreate(['signature' => $signatureDto->signature], $signatureDto->defaults);
        $tokenSignature = TokenSignature::firstOrCreate([
            'token_id' => $token->id,
            'signature_id' => $signature->id,
        ]);

        $useWordImmediately = $tokenSignature->wasRecentlyCreated || $listType === 'fun';

        $tokenSignatureWord = TokenSignatureWord::create(
            [
                'token_signature_id' => $tokenSignature->id,
                'list_type' => $listType,
                'word' => $this->normalize($word),
                'is_deferred' => !$useWordImmediately,
                'committed_at' => $committed_at,
            ]
        );

        // Retroactively defer the first non-fun word if a fun word exists under the same signature
        if (!$tokenSignature->wasRecentlyCreated) {
            $firstWord = $tokenSignature->words()->orderBy('id')->first();
            if ($firstWord && $firstWord->list_type !== 'fun' && !$firstWord->is_deferred) {
                $funExists = $tokenSignature->words()->where('list_type', 'fun')->exists();
                if ($funExists) {
                    $firstWord->is_deferred = true;
                    $firstWord->save();
                }
            }
        }

        return $tokenSignatureWord;
    }

    public function findMatchingTokenSignatureWords(Signature $targetSignature, array $options = []): Collection
    {
        $t0 = microtime(true);
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);

        $cacheEnabled = (bool) config('search.enable_match_cache', false);

        if ($cacheEnabled) {
            $ttl = (int) config('search.match_cache_ttl', 120);
            $cacheKey = $this->buildCacheKey($targetSignature->signature, $includeBoring, $filterList, $filterToken);

            $ids = Cache::get($cacheKey);
            if (is_array($ids)) {
                if (empty($ids)) {
                    try { Log::info('WordMatchService: cache_hit=1 empty=1'); } catch (Throwable $e) {}
                    return collect();
                }
                $tRh0 = microtime(true);
                $models = TokenSignatureWord::query()
                    ->whereIn('id', $ids)
                    ->get();
                $rehydrateMs = (int) round((microtime(true) - $tRh0) * 1000);
                try { Log::info('WordMatchService: cache_hit=1 ids=' . count($ids) . ' rehydrate_ms=' . $rehydrateMs); } catch (Throwable $e) {}
                return $models;
            } else {
                try { Log::info('WordMatchService: cache_hit=0'); } catch (Throwable $e) {}
            }
        }

        // Build an Eloquent query that returns exact subset candidates using precomputed counts (pure SQL pruning)
        $query = TokenSignatureWord::query()
            ->where('is_deferred', false)
            ->when($filterList !== '', function($q) use ($filterList) {
                $q->where('list_type', $filterList);
            }, function($q) use ($includeBoring) {
                if (!$includeBoring) {
                    $q->where('list_type', '!=', 'boring');
                }
            })
            ->whereHas('tokenSignature', function ($q) use ($targetSignature, $filterToken) {
                // Constrain via related signatures table (FK: token_signatures.signature_id)
                $q->whereHas('signature', function ($qs) use ($targetSignature) {
                    $qs->where('length', '<=', (int) $targetSignature->length);
                    foreach (range('a','z') as $ch) {
                        $n = (int) ($targetSignature->{$ch . '_count'} ?? 0);
                        // Keep exact 0 equality for previous behavior; otherwise allow <= n
                        if ($n > 0) {
                            $qs->where($ch . '_count', '<=', $n);
                        } else {
                            $qs->where($ch . '_count', '=', 0);
                        }
                    }
                });

                if ($filterToken !== '') {
                    $q->whereHas('token', function ($t) use ($filterToken) {
                        $t->where('name', $filterToken);
                    });
                }
            });

        $tQ0 = microtime(true);
        $matches = $query->get();
        $queryMs = (int) round((microtime(true) - $tQ0) * 1000);
        $count = $matches->count();
        $totalMs = (int) round((microtime(true) - $t0) * 1000);

        try {
            Log::info('WordMatchService: count=' . $count . ' query_ms=' . $queryMs . ' total_ms=' . $totalMs);
        } catch (Throwable $e) {}

        if ($cacheEnabled) {
            try {
                $ids = $matches->pluck('id')->values()->all();
                if (!empty($ids)) {
                    Cache::put($cacheKey, $ids, $ttl);
                    Log::info('WordMatchService: cache_store ids=' . count($ids) . ' ttl_s=' . $ttl);
                } else {
                    Log::info('WordMatchService: cache_skip_empty=1');
                }
            } catch (Throwable $e) {
                // ignore cache errors
            }
        }

        return $matches;
    }

    private function buildCacheKey(string $targetSignature, bool $includeBoring, string $filterList, string $filterToken): string
    {
        // Caching key depends on target signature and filters
        return 'match:' . $targetSignature . ':b=' . ($includeBoring ? '1' : '0') . ':list=' . $filterList . ':tok=' . $filterToken;
    }

    /**
     * Find and link matching TokenSignatureWord rows to a target.
     * Wraps findMatchingTokenSignatureWords + bulk insert with signature resolution.
     */
    public function linkMatchesToTarget(Target $target, array $options = []): void
    {
        $words = $this->findMatchingTokenSignatureWords($target->signature, $options);
        TargetTokenSignatureWord::bulkInsertOrIgnore($target, $words);
    }

    /**
     * @param Collection<TokenSignatureWord> $matchingTokenSignatureWords
     * @return array[]
     */
    public function extractTargetTokenSignatureWordMinimumLengths(Collection $matchingTokenSignatureWords): array
    {
        $storedWordBasedMins = [];
        $matchingWordBasedMins = [];
        foreach($matchingTokenSignatureWords as $matchedWord) {
            $tokenSignature = $matchedWord->tokenSignature;
            $length = (int) ($tokenSignature->signature->length ?? 0);
            $token_id = $tokenSignature->token_id;
            $storedWordBasedMins[$token_id] = $tokenSignature->token->min_length;
            if (!isset($matchingWordBasedMins[$token_id]) || $length < $matchingWordBasedMins[$token_id]) {
                $matchingWordBasedMins[$token_id] = $length;
            }
        }
        return array($storedWordBasedMins, $matchingWordBasedMins);
    }
}
