<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WordMatchService
{

    /**
     * Find matching words grouped by token_type and list_type.
     *
     * @param string $sourceName
     * @param array{token?:string,list?:string,include_boring?:bool} $options
     * @return array{source:string, signature:string, total:int, groups: array<string, array<string, array<int,array{id:int,word:string,signature:string}>>>>
     */
    public function findMatches(string $sourceSignature, array $options = []): array
    {
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);

        $srcLen = strlen($sourceSignature);

        $query = DB::table('words')->select('id', 'word', 'token_type', 'list_type', 'signature');
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
        $query->chunkById(1000, function ($rows) use (&$grouped, &$total, $sourceSignature, $srcLen) {
            foreach ($rows as $r) {
                $len = strlen($r->signature ?? '');
                if ($len > $srcLen) continue;
                if (!$this->sig->isSubset((string)$r->signature, $sourceSignature)) continue;
                $tok = (string)$r->token_type;
                $lst = (string)$r->list_type;
                $grouped[$tok][$lst][] = [
                    'id' => (int)$r->id,
                    'word' => (string)$r->word,
                    'signature' => (string)$r->signature,
                ];
                $total++;
            }
        }, 'id');

        return [
            'total' => $total,
            'groups' => $grouped,
        ];
    }
}
