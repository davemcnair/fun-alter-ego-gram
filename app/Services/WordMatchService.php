<?php

namespace App\Services;

use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\DB;

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
}
