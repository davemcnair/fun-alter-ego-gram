<?php

namespace App\Services;

use App\Models\SourceName;
use App\Models\Token;
use App\Models\Word;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WordMatchService
{
    use HelpsMatchWords;

    public function findMatches(string $sourceSignature, array $options = []): array
    {
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);

        $srcLen = strlen($sourceSignature);

        // Build base query and apply filters
        $query = DB::table('words')
            ->select('id', 'word', 'token_type', 'list_type', 'signature')
            ->where('use_for_search', 1);
        if ($filterToken !== '') $query->where('token_type', $filterToken);
        if ($filterList !== '') {
            $query->where('list_type', $filterList);
        } else {
            if (!$includeBoring) {
                $query->where('list_type', '!=', 'boring');
            }
        }
        // Push signature-length filter down to SQL for efficiency
        $query->whereRaw('LENGTH(signature) <= ?', [$srcLen]);

        $grouped = [];

        // Stream rows in id order to avoid loading entire table
        $query->orderBy('id');
        $query->chunkById(1000, function ($rows) use (&$grouped, $sourceSignature) {
            foreach ($rows as $r) {
                // Core subset check based on precomputed signatures
                if (!$this->isSubset((string)$r->signature, $sourceSignature)) continue;
                // Group by token and list types
                $grouped[$r->token_type][$r->list_type][] = [
                    'id' => (int)$r->id,
                    'word' => (string)$r->word,
                    'signature' => (string)$r->signature,
                ];
            }
        }, 'id');

        return $grouped;
    }

    public function storeNewMatchingWords(SourceName $sourceName, bool $includeBoring): array
    {
        $wordsQuery = Word::whereDoesntHave('matchedWords', function($q) use ($sourceName){
                $q->where('source_name_id', $sourceName->id);
            })
            // words must always be unique by (token_type, signature, use_for_search)
            ->where('use_for_search', true)
            ->select('id', 'token_type', 'signature');
        if (!$includeBoring) {
            $wordsQuery->where('list_type', '!=', 'boring');
        }
        Log::info($wordsQuery->count() . ' new words');
        $anyWordsFoundForToken = [];
        $wordsQuery->chunkById(1000, function ($rows) use (&$anyWordsFoundForToken, $sourceName) {
            $sourceSignature = $sourceName->signature;
            $sourceNameLength = strlen($sourceSignature);
            $matches = [];
            foreach ($rows as $r) {
                $signature = $r->signature;
                $length = strlen($signature);
                if ($length > $sourceNameLength) continue;
                if (!$this->isSubset($signature, $sourceSignature)) continue;
                $token_type = $r->token_type;
                $anyWordsFoundForToken[$token_type] = true;
                $matches[] = [
                    'source_name_id' => $sourceName->id,
                    'word_id' => (int)$r->id,
                    'used' => false
                ];
            }
            if (!empty($matches)) {
                DB::table('matched_words')->insert($matches);
            }
        }, 'id');
        return array_keys( $anyWordsFoundForToken);
    }

    public function extractMatchingTokenWordMinimumLengths(SourceName $sourceName, array $liveTokens): array
    {
        $storedWordBasedMins = Token::whereIn('name', $liveTokens)
            ->pluck('min_length', 'name')
            ->toArray();
        // effective mins lengths can be longer based on matching words
        $matchingWordBasedMins = [];
        $sourceSignature = $sourceName->signature;
        $sourceNameLength = strlen($sourceSignature);
        foreach($sourceName->matchedWords as $matchedWord) {
            $signature = $matchedWord->word->signature;
            $length = strlen($signature);
            if ($length > $sourceNameLength) continue;
            if (!$this->isSubset($signature, $sourceSignature)) continue;
            $token_type = $matchedWord->word->token_type;
            if (!isset($matchingWordBasedMins[$token_type]) || $length < $matchingWordBasedMins[$token_type]) {
                $matchingWordBasedMins[$token_type] = $length;
            }
        }
        return array($storedWordBasedMins, $matchingWordBasedMins);
    }
}
