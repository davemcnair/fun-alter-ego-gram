<?php

namespace App\Services;

use App\Models\Target;
use App\Models\TargetTokenSignatureWord;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WordMatchService
{
    use HelpsMatchWords;

    /**
     * Add a word for a token/list into TokenSignature/TokenSignatureWord tables.
     * Mirrors ImportWordLists behavior for existing/new signatures and deferral rules.
     *
     * Returns the TokenSignatureWord created or found, or null if the word normalizes to empty
     * or the token name does not exist.
     */
    public function addTokenWord(string $tokenName, string $word, string $listType): ?TokenSignatureWord
    {
        $signature = $this->makeSignature($word);
        if ($signature === '') {
            return null;
        }

        // Ensure token exists; if missing and is one of known token names, create minimal record; otherwise return null
        $token = Token::where('name', $tokenName)->first();
        if (!$token) {
            if (in_array($tokenName, Token::NAMES, true)) {
                $token = Token::create([
                    'name' => $tokenName,
                    'prio' => 0,
                    'min_length' => 0,
                    'allow_nearly' => false,
                    'has_fun' => false,
                    'has_boring' => false,
                    'max_multiples' => 1,
                ]);
            } else {
                return null;
            }
        }

        // Compute per-letter counts and signature length for new TokenSignature rows
        $letterCounts = $this->letterCountsFromSignature($signature);
        $defaults = ['sig_len' => strlen($signature)];
        foreach (range('a', 'z') as $ch) {
            $defaults[$ch . '_count'] = (int)($letterCounts[$ch] ?? 0);
        }

        $tokenSignature = TokenSignature::firstOrCreate([
            'token_id' => $token->id,
            'signature' => $signature,
        ], $defaults);

        $isDeferred = !$tokenSignature->wasRecentlyCreated && $listType !== 'fun';

        // Avoid duplicate unique constraint violations; return existing if present
        $tokenSignatureWord = TokenSignatureWord::firstOrCreate(
            [
                'token_signature_id' => $tokenSignature->id,
                'list_type' => $listType,
                'word' => $this->normalize($word),
            ],
            [
                'is_deferred' => $isDeferred,
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

    public function findMatchingTokenSignatureWords(string $targetSignature, array $options = []): Collection
    {
        $t0 = microtime(true);
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);
        $srcLen = strlen($targetSignature);

        // Flags context for observability (may not alter behavior here)
        $sqlSubsetPruning = (bool) config('search.sql_subset_pruning', false);
        $verifyInPhp = (bool) config('search.verify_subset_in_php', true);

        // Caching key depends on target signature and filters
        $cacheEnabled = (bool) config('search.enable_match_cache', false);
        $ttl = (int) config('search.match_cache_ttl', 120);
        $cacheKey = 'match:' . $targetSignature . ':b=' . ($includeBoring ? '1' : '0') . ':list=' . $filterList . ':tok=' . $filterToken;

        if ($cacheEnabled) {
            $ids = Cache::get($cacheKey);
            if (is_array($ids)) {
                if (empty($ids)) {
                    try { Log::info('WordMatchService: cache_hit=1 empty=1'); } catch (\Throwable $e) {}
                    return collect();
                }
                // Rehydrate models with required relations
                $tRh0 = microtime(true);
                $models = TokenSignatureWord::query()
                    ->with(['tokenSignature.token'])
                    ->whereIn('id', $ids)
                    ->get();
                $rehydrateMs = (int) round((microtime(true) - $tRh0) * 1000);
                try { Log::info('WordMatchService: cache_hit=1 ids=' . count($ids) . ' rehydrate_ms=' . $rehydrateMs); } catch (\Throwable $e) {}
                return $models;
            } else {
                try { Log::info('WordMatchService: cache_hit=0'); } catch (\Throwable $e) {}
            }
        }

        // Build an Eloquent query that returns TokenSignatureWord models with relations,
        // so downstream services (SignatureFillService) can access tokenSignature->signature/token_id.
        $query = TokenSignatureWord::query()
            ->with(['tokenSignature.token'])
            ->where('is_deferred', false)
            ->whereHas('tokenSignature', function ($q) use ($srcLen, $filterToken, $sqlSubsetPruning, $targetSignature) {
                if ($sqlSubsetPruning) {
                    // Use precomputed numeric columns for exact subset pruning
                    $q->where('sig_len', '<=', $srcLen);
                    // Build letter predicates: letters present must be <= target counts; absent letters must be 0
                    $counts = $this->letterCountsFromSignature($targetSignature);
                    foreach (range('a','z') as $ch) {
                        $n = (int)($counts[$ch] ?? 0);
                        if ($n > 0) {
                            $q->where($ch . '_count', '<=', $n);
                        } else {
                            $q->where($ch . '_count', '=', 0);
                        }
                    }
                } else {
                    // Fallback to legacy length filter (string length)
                    $q->whereRaw('LENGTH(signature) <= ?', [$srcLen]);
                }
                if ($filterToken !== '') {
                    $q->whereHas('token', function ($t) use ($filterToken) {
                        $t->where('name', $filterToken);
                    });
                }
            });

        if ($filterList !== '') {
            $query->where('list_type', $filterList);
        } elseif (!$includeBoring) {
            $query->where('list_type', '!=', 'boring');
        }

        // Fetch models and optionally filter by subset relation of signatures in PHP
        $tQ0 = microtime(true);
        $all = $query->get();
        $queryMs = (int) round((microtime(true) - $tQ0) * 1000);
        $preCount = $all->count();

        // If SQL-pruned path yielded nothing, defensively fall back to legacy length-only filtering
        // to accommodate environments where counts weren't backfilled yet.
        if ($preCount === 0 && $sqlSubsetPruning) {
            try { Log::warning('WordMatchService: sql_pruning_empty_fallback=1 (falling back to legacy LENGTH(signature) filter)'); } catch (\Throwable $e) {}
            $fallbackQuery = TokenSignatureWord::query()
                ->with(['tokenSignature.token'])
                ->where('is_deferred', false)
                ->when($filterList !== '', function($q) use ($filterList) {
                    $q->where('list_type', $filterList);
                }, function($q) use ($includeBoring) {
                    if (!$includeBoring) {
                        $q->where('list_type', '!=', 'boring');
                    }
                })
                ->whereHas('tokenSignature', function ($q) use ($srcLen, $filterToken) {
                    $q->whereRaw('LENGTH(signature) <= ?', [$srcLen]);
                    if ($filterToken !== '') {
                        $q->whereHas('token', function ($t) use ($filterToken) {
                            $t->where('name', $filterToken);
                        });
                    }
                });
            $tQ1 = microtime(true);
            $all = $fallbackQuery->get();
            $queryMs = (int) round((microtime(true) - $tQ1) * 1000);
            $preCount = $all->count();
        }

        if ($verifyInPhp || $sqlSubsetPruning) {
            // Always verify subset when coming from SQL-pruned path or explicitly requested
            $tF0 = microtime(true);
            $matches = $all->filter(function (TokenSignatureWord $tsw) use ($targetSignature) {
                return $this->isSubset($tsw->tokenSignature->signature, $targetSignature);
            })->values();
            $filterMs = (int) round((microtime(true) - $tF0) * 1000);
        } else {
            $matches = $all->values();
            $filterMs = 0;
        }

        $postCount = $matches->count();
        $totalMs = (int) round((microtime(true) - $t0) * 1000);
        try {
            Log::info('WordMatchService: flags sql_subset_pruning=' . ($sqlSubsetPruning ? '1' : '0') . ' verify_subset_in_php=' . ($verifyInPhp ? '1' : '0'));
            Log::info('WordMatchService: pre_count=' . $preCount . ' post_count=' . $postCount . ' query_ms=' . $queryMs . ' filter_ms=' . $filterMs . ' total_ms=' . $totalMs);
        } catch (\Throwable $e) {}

        if ($cacheEnabled) {
            try {
                $ids = $matches->pluck('id')->values()->all();
                // Do not cache empty results to avoid "sticky" emptiness on first-run before data import
                if (!empty($ids)) {
                    Cache::put($cacheKey, $ids, $ttl);
                    Log::info('WordMatchService: cache_store ids=' . count($ids) . ' ttl_s=' . $ttl);
                } else {
                    Log::info('WordMatchService: cache_skip_empty=1');
                }
            } catch (\Throwable $e) {
                // ignore cache errors
            }
        }

        // Helpful hint if the dataset is empty in dev environments
        if ($preCount === 0 && TokenSignatureWord::query()->count() === 0) {
            try { Log::warning('WordMatchService: no TokenSignatureWord rows found. Did you import word lists? Try: php artisan words:import base=resources/token_words'); } catch (\Throwable $e) {}
        }

        return $matches;
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
            $length = strlen($tokenSignature->signature);
            $token_id = $tokenSignature->token_id;
            $storedWordBasedMins[$token_id] = $tokenSignature->token->min_length;
            if (!isset($matchingWordBasedMins[$token_id]) || $length < $matchingWordBasedMins[$token_id]) {
                $matchingWordBasedMins[$token_id] = $length;
            }
        }
        return array($storedWordBasedMins, $matchingWordBasedMins);
    }
}
