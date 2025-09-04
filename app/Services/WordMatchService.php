<?php

namespace App\Services;

use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\DB;

class WordMatchService
{
    use HelpsMatchWords;

    /**
     * Find matching words grouped by token_type and list_type, with optional anagram deduplication.
     *
     * @param string $sourceSignature
     * @param array{token?:string,list?:string,include_boring?:bool,dedupe_anagrams?:bool} $options
     * @return array{total:int, groups: array<string, array<string, array<int,array{id:int,word:string,signature:string}>>>}
     */
    public function findMatches(string $sourceSignature, array $options = []): array
    {
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);
        $dedupeAnagrams = array_key_exists('dedupe_anagrams', $options) ? (bool)$options['dedupe_anagrams'] : true;

        $srcLen = strlen($sourceSignature);

        $query = DB::table('words')->select('id', 'word', 'token_type', 'list_type', 'signature')->where('use_for_search', 1);
        if ($filterToken !== '') $query->where('token_type', $filterToken);
        if ($filterList !== '') {
            $query->where('list_type', $filterList);
        } else {
            if (!$includeBoring) {
                $query->where('list_type', '!=', 'boring');
            }
        }

        $grouped = [];
        $total = 0;

        $query->orderBy('id');
        $query->chunkById(1000, function ($rows) use (&$grouped, &$total, $sourceSignature, $srcLen, $dedupeAnagrams) {
            foreach ($rows as $r) {
                $len = strlen($r->signature ?? '');
                if ($len > $srcLen) continue;
                if (!$this->isSubset((string)$r->signature, $sourceSignature)) continue;
                $tok = (string)$r->token_type;
                $lst = (string)$r->list_type;
                if ($dedupeAnagrams) {
                    // Keep only the first representative per signature within token/list
                    $sig = (string)$r->signature;
                    $bucket =& $grouped[$tok][$lst];
                    if (!isset($bucket)) { $bucket = []; }
                    // Use an index by signature to avoid duplicates
                    if (!isset($bucket['__sig_index'])) { $bucket['__sig_index'] = []; }
                    if (isset($bucket['__sig_index'][$sig])) {
                        // already have a representative; skip additional anagrams
                        continue;
                    }
                    $bucket['__sig_index'][$sig] = true;
                    $bucket[] = [
                        'id' => (int)$r->id,
                        'word' => (string)$r->word,
                        'signature' => $sig,
                    ];
                } else {
                    $grouped[$tok][$lst][] = [
                        'id' => (int)$r->id,
                        'word' => (string)$r->word,
                        'signature' => (string)$r->signature,
                    ];
                }
                $total++;
            }
        }, 'id');

        // Clean helper indices
        foreach ($grouped as $tok => &$byList) {
            foreach ($byList as $lst => &$items) {
                if (is_array($items) && array_key_exists('__sig_index', $items)) {
                    unset($items['__sig_index']);
                    // Reindex to 0..n
                    $items = array_values($items);
                }
            }
        }
        unset($byList, $items);

        return [
            'total' => $total,
            'groups' => $grouped,
        ];
    }
}
