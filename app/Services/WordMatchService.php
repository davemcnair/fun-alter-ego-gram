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
use Illuminate\Support\Facades\DB;
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

        $useWordImmediately = $listType === 'fun';

        $tokenSignatureWord = TokenSignatureWord::firstOrCreate(
            [
                'token_signature_id' => $tokenSignature->id,
                'list_type' => $listType,
                'word' => $this->normalize($word),
            ],
            [
                'is_deferred' => !$useWordImmediately,
                'committed_at' => $committed_at,
            ]
        );

        // Retroactively defer the earliest non-fun word when a FUN word exists on the same token/signature
        $shouldCheckRetroactive = $listType === 'fun';
        if ($shouldCheckRetroactive) {
            $firstNonFun = $tokenSignature->words()
                ->where('list_type', '!=', 'fun')
                ->orderBy('id')
                ->first();
            if ($firstNonFun && !$firstNonFun->is_deferred) {
                $funExists = $listType === 'fun' || $tokenSignature->words()->where('list_type', 'fun')->exists();
                if ($funExists) {
                    $firstNonFun->is_deferred = true;
                    $firstNonFun->save();
                }
            }
        }

        return $tokenSignatureWord;
    }

//    public function findMatchingTokenSignatureWords(Signature $targetSignature, bool $includeBoring = false): Collection
//    {
//        $cacheEnabled = (bool) config('search.enable_match_cache', false);
//
//        if ($cacheEnabled) {
//            $ttl = (int) config('search.match_cache_ttl', 120);
//            $cacheKey = $this->buildCacheKey($targetSignature->signature, $includeBoring);
//
//            $ids = Cache::get($cacheKey);
//            if (is_array($ids)) {
//                if (empty($ids)) {
//                    return collect();
//                }
//                return TokenSignatureWord::query()
//                    ->whereIn('id', $ids)
//                    ->get();
//            }
//        }
//
//        // Build an Eloquent query that returns exact subset candidates using precomputed letterCounts (pure SQL pruning)
//        $query = TokenSignatureWord::query()
//            ->where('is_deferred', false)
//            ->when(!$includeBoring, function($q) {
//                $q->where('list_type', '!=', 'boring');
//            })
//            ->whereHas('tokenSignature', function ($q) use ($targetSignature) {
//                // Constrain via related signatures table (FK: token_signatures.signature_id)
//                $q->whereHas('signature', function ($qs) use ($targetSignature) {
//                    $qs->where('length', '<=', (int) $targetSignature->length);
//                    foreach (range('a','z') as $ch) {
//                        $n = (int) ($targetSignature->{$ch . '_count'} ?? 0);
//                        // Keep exact 0 equality for previous behavior; otherwise allow <= n
//                        if ($n > 0) {
//                            $qs->where($ch . '_count', '<=', $n);
//                        } else {
//                            $qs->where($ch . '_count', '=', 0);
//                        }
//                    }
//                });
//            });
//
//
//        $matches = $query->get();
//        $count = $matches->count();
//
//        if ($cacheEnabled) {
//            try {
//                $ids = $matches->pluck('id')->values()->all();
//                if (!empty($ids)) {
//                    Cache::put($cacheKey, $ids, $ttl);
//                }
//            } catch (Throwable $e) {
//                // ignore cache errors
//            }
//        }
//
//        return $matches;
//    }
//
    public function findMatchingTokenSignatures(Signature $targetSignature): Collection
    {
        // Don't eager load words here - we'll use a query-based approach in bulkInsertOrIgnore
        // to avoid loading all words into memory at once
        return TokenSignature::query()
            ->whereHas('signature', function ($qs) use ($targetSignature) {
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
            })
            ->get();
    }

    private function buildCacheKey(string $targetSignature, bool $includeBoring, string $filterList, string $filterToken): string
    {
        // Caching key depends on target signature and filters
        return 'match:' . $targetSignature . ':b=' . ($includeBoring ? '1' : '0') . ':list=' . $filterList . ':tok=' . $filterToken;
    }

    public function extractTargetTokenSignatureMinimumLengths(Collection $targetTokenSignatures): array
    {
        $storedSignatureBasedMins = [];
        $matchingSignatureBasedMins = [];
        foreach($targetTokenSignatures as $targetTokenSignature) {
            $tokenSignature = $targetTokenSignature->tokenSignature;
            $length = (int) ($tokenSignature->signature->length ?? 0);
            $token_id = $tokenSignature->token_id;
            $storedSignatureBasedMins[$token_id] = $tokenSignature->token->min_length;
            if (!isset($matchingSignatureBasedMins[$token_id]) || $length < $matchingSignatureBasedMins[$token_id]) {
                $matchingSignatureBasedMins[$token_id] = $length;
            }
        }
        return array($storedSignatureBasedMins, $matchingSignatureBasedMins);
    }

    /**
     * Extract minimum lengths using SQL queries to avoid loading all models into memory.
     * This is memory-efficient for targets with many token signatures.
     */
    public function extractTargetTokenSignatureMinimumLengthsFromQuery(Target $target): array
    {
        // Use SQL aggregation to compute minimums without loading all models
        $results = DB::table('target_token_signatures as tts')
            ->join('token_signatures as ts', 'ts.id', '=', 'tts.token_signature_id')
            ->join('signatures as s', 's.id', '=', 'ts.signature_id')
            ->join('tokens as t', 't.id', '=', 'ts.token_id')
            ->where('tts.target_id', $target->id)
            ->select([
                'ts.token_id',
                't.min_length as stored_min',
                DB::raw('MIN(s.length) as matched_min')
            ])
            ->groupBy('ts.token_id', 't.min_length')
            ->get();

        $storedSignatureBasedMins = [];
        $matchingSignatureBasedMins = [];
        
        foreach ($results as $row) {
            $token_id = (int) $row->token_id;
            $storedSignatureBasedMins[$token_id] = (int) $row->stored_min;
            $matchingSignatureBasedMins[$token_id] = (int) $row->matched_min;
        }

        return [$storedSignatureBasedMins, $matchingSignatureBasedMins];
    }
}
