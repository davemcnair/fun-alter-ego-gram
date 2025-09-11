<?php

namespace App\Services;

use App\Models\SourceName;
use App\Models\SourceNameMatchedWord;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;

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

    public function findMatchingTokenSignatureWordIds(string $sourceSignature, array $options = []): array
    {
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);

        $srcLen = strlen($sourceSignature);

        // Use TokenSignature/TokenSignatureWord joined with Token to respect deferrals and normalized signatures.
        $query = TokenSignatureWord::query()
            ->join('token_signatures as ts', 'ts.id', '=', 'token_signature_words.token_signature_id')
            ->join('tokens as t', 't.id', '=', 'ts.token_id')
            ->where('token_signature_words.is_deferred', false)
            ->whereRaw('LENGTH(ts.signature) <= ?', [$srcLen]);

        if ($filterToken !== '') {
            $query->where('t.name', $filterToken);
        }
        if ($filterList !== '') {
            $query->where('token_signature_words.list_type', $filterList);
        } else {
            if (!$includeBoring) {
                $query->where('token_signature_words.list_type', '!=', 'boring');
            }
        }

        // Select only required fields; order by tsw.id for stable chunking
        $query->select([
            'token_signature_words.id',
            'ts.signature as signature',
        ]);

        $matchingIds = [];
        $query->chunk(1000, function ($rows) use (&$grouped, $sourceSignature) {
            foreach ($rows as $r) {
                if (!$this->isSubset((string)$r->signature, $sourceSignature)) continue;
                $matchingIds[] = $r->id;
            }
        });

        return $matchingIds;
    }

    public function storeNewSourceNameMatchedTokenSignatureWords(SourceName $sourceName, bool $includeBoring = false): array
    {
        $matchingIds = $this->findMatchingTokenSignatureWordIds($sourceName->signature, ['include_boring' => $includeBoring]);
        foreach ($matchingIds as $id) {
            $sourceName->sourceNameMatchedWords()->create();
        }
    }

    public function extractMatchingTokenWordMinimumLengths(SourceName $sourceName, array $liveTokenIds): array
    {
        $storedWordBasedMins = Token::whereIn('id', $liveTokenIds)
            ->pluck('min_length', 'id')
            ->toArray();
        // effective mins lengths can be longer based on matching words (legacy words table)
        $matchingWordBasedMins = [];
        $sourceSignature = $sourceName->signature;
        /** @var SourceNameMatchedWord $matchedWord */
        foreach($sourceName->sourceNameMatchedWords as $matchedWord) {
            $signature = $matchedWord->tokenSignatureWord->tokenSignature->signature;
            if (!$this->isSubset($signature, $sourceSignature)) continue;
            $length = strlen($signature);
            $token_id = $matchedWord->word->tokenSignatureWord->tokenSignature->token_id;
            if (!isset($matchingWordBasedMins[$token_id]) || $length < $matchingWordBasedMins[$token_id]) {
                $matchingWordBasedMins[$token_id] = $length;
            }
        }
        return array($storedWordBasedMins, $matchingWordBasedMins);
    }
}
