<?php

namespace App\Services;

use App\Models\Target;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

        $tokenSignature = TokenSignature::firstOrCreate([
            'token_id' => $token->id,
            'signature' => $signature,
        ]);

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
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);
        $srcLen = strlen($targetSignature);

        // Build an Eloquent query that returns TokenSignatureWord models with relations,
        // so downstream services (SignatureFillService) can access tokenSignature->signature/token_id.
        $query = TokenSignatureWord::query()
            ->with(['tokenSignature.token'])
            ->where('is_deferred', false)
            ->whereHas('tokenSignature', function ($q) use ($srcLen, $filterToken) {
                $q->whereRaw('LENGTH(signature) <= ?', [$srcLen]);
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

        // Fetch models and filter by subset relation of signatures in PHP
        $all = $query->get();
        Log::info('Unfiltered matching token signature words: ' . $all->count());
        $matches = $all->filter(function (TokenSignatureWord $tsw) use ($targetSignature) {
            return $this->isSubset($tsw->tokenSignature->signature, $targetSignature);
        })->values();
        Log::info('Filtered matching token signature words: ' . $matches->count());
        return $matches;
    }

    public function storeNewTargetMatchedTokenSignatureWords(Target $target, bool $includeBoring = false): Collection
    {
        $matchingTokenSignatureWords = $this->findMatchingTokenSignatureWords($target->signature, ['include_boring' => $includeBoring]);
        if ($matchingTokenSignatureWords->count()) {
            $rows = $matchingTokenSignatureWords->map(function ($tsw) use ($target) {
                return [
                    'target_id' => $target->id,
                    'token_signature_word_id' => $tsw->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            });
            DB::table('target_token_signature_words')->insert($rows->toArray());
        }
        return $matchingTokenSignatureWords;
    }

    /**
     * Map TokenSignatureWord IDs to unique token_ids
     * @param array<int,int> $tswIds
     * @return array<int,int> list of unique token_ids
     */
    private function extractTokenIdsFromTsws(Collection $tsws): array
    {
        if (empty($tswIds)) return [];
        $tokenIds = $tsws
            ->pluck('token_id')
            ->unique()
            ->values()
            ->all();
        // Normalize to ints
        return array_map('intval', $tokenIds);
    }

    public function extractMatchingTokenWordMinimumLengths(string $targetSignature, Collection $tokenSignatureWords): array
    {
        $storedWordBasedMins = [];
        $matchingWordBasedMins = [];
        /** @var TokenSignatureWord $matchedWord */
        foreach($tokenSignatureWords as $matchedWord) {
            $signature = $matchedWord->tokenSignature->signature;
            if (!$this->isSubset($signature, $targetSignature)) continue;
            $length = strlen($signature);
            $token_id = $matchedWord->tokenSignature->token_id;
            $storedWordBasedMins[$token_id] = $matchedWord->tokenSignature->token->min_length;
            if (!isset($matchingWordBasedMins[$token_id]) || $length < $matchingWordBasedMins[$token_id]) {
                $matchingWordBasedMins[$token_id] = $length;
            }
        }
        return array($storedWordBasedMins, $matchingWordBasedMins);
    }
}
