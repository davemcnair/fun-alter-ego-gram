<?php

namespace App\Services;

use App\Models\SourceName;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\DB;
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

    public function findMatches(string $sourceSignature, array $options = []): array
    {
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);

        $srcLen = strlen($sourceSignature);

        // If there are no token_signature_words yet, fall back to legacy words table behavior
        if (DB::table('token_signature_words')->count() === 0) {
            return $this->findMatchesFromWords($sourceSignature, $options);
        }

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

        $grouped = [];

        // Select only required fields; order by tsw.id for stable chunking
        $query->orderBy('token_signature_words.id');
        $query->select([
            'token_signature_words.id',
            'token_signature_words.word as word',
            'token_signature_words.list_type as list_type',
            'ts.signature as signature',
            't.name as token_type',
        ]);

        $query->chunk(1000, function ($rows) use (&$grouped, $sourceSignature) {
            foreach ($rows as $r) {
                // Subset check against the source signature
                if (!$this->isSubset((string)$r->signature, $sourceSignature)) continue;
                $token = (string)$r->token_type;
                $list  = (string)$r->list_type;
                $grouped[$token][$list][] = [
                    'id' => (int)$r->id,
                    'word' => (string)$r->word,
                    'signature' => (string)$r->signature,
                ];
            }
        });

        return $grouped;
    }
    public function storeNewSourceNameMatchedWords(SourceName $sourceName, bool $includeBoring = false): array
    {
        $sourceSignature = $sourceName->signature;
        $srcLen = strlen($sourceSignature);

        // Prefilter candidates from legacy words table
        $query = \App\Models\Word::query()
            ->where('use_for_search', true)
            ->whereRaw('LENGTH(signature) <= ?', [$srcLen]);
        if (!$includeBoring) {
            $query->where('list_type', '!=', 'boring');
        }

        $insertedTokens = [];
        $toInsert = [];

        // Build a set of existing word_ids for this source to avoid duplicates
        $existing = DB::table('matched_words')
            ->where('source_name_id', $sourceName->id)
            ->pluck('word_id')
            ->all();
        $existingSet = array_fill_keys($existing, true);

        $prefilterCount = $query->count();
        Log::info(sprintf('%d new words (pre-filtered by length <= %d) for source %d:%s',
            $prefilterCount,
            $srcLen,
            $sourceName->id,
            $sourceName->name
        ));

        $query->orderBy('id')->chunkById(1000, function ($rows) use (&$toInsert, &$insertedTokens, $sourceSignature, $sourceName, &$existingSet) {
            foreach ($rows as $w) {
                if (isset($existingSet[$w->id])) continue;
                if (!$this->isSubset((string)$w->signature, $sourceSignature)) continue;
                $toInsert[] = [
                    'source_name_id' => $sourceName->id,
                    'word_id' => $w->id,
                    'used' => false,
                ];
                $existingSet[$w->id] = true;
                $insertedTokens[$w->token_type] = true;
            }
            if (count($toInsert) >= 1000) {
                DB::table('matched_words')->insert($toInsert);
                $toInsert = [];
            }
        }, 'id');

        if (!empty($toInsert)) {
            DB::table('matched_words')->insert($toInsert);
        }

        return array_keys($insertedTokens);
    }

    public function extractMatchingTokenWordMinimumLengths(SourceName $sourceName, array $liveTokens): array
    {
        $storedWordBasedMins = Token::whereIn('name', $liveTokens)
            ->pluck('min_length', 'name')
            ->toArray();
        // effective mins lengths can be longer based on matching words (legacy words table)
        $matchingWordBasedMins = [];
        $sourceSignature = $sourceName->signature;
        foreach($sourceName->matchedWords as $matchedWord) {
            $signature = $matchedWord->word->signature;
            if (!$this->isSubset($signature, $sourceSignature)) continue;
            $length = strlen($signature);
            $token_type = $matchedWord->word->token_type;
            if (!isset($matchingWordBasedMins[$token_type]) || $length < $matchingWordBasedMins[$token_type]) {
                $matchingWordBasedMins[$token_type] = $length;
            }
        }
        return array($storedWordBasedMins, $matchingWordBasedMins);
    }
}
