<?php

namespace App\Services;

use App\Models\Pattern;
use App\Models\Token;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListPatternsService
{
    use HelpsMatchWords;

    /**
     * Plain list of patterns (no source-based filtering).
     * Supports like, pagination. Returns min_total_length per row.
     *
     * @param array{like?:string, limit?:int, page?:int} $opts
     * @return array{
     *   meta: array{total:int, page:int, pages:int, count:int},
     *   rows: array<int, array{popularity_rank:int, template:string, min:int}>
     * }
     */
    public function list(array $opts): array
    {
        $limit = max(1, (int)($opts['limit'] ?? 500));
        $page = max(1, (int)($opts['page'] ?? 1));
        $like = (string)($opts['like'] ?? '');

        $query = DB::table('patterns')->orderBy('popularity_rank');
        if ($like !== '') $query->where('template', 'like', '%' . $like . '%');

        $allRows = $query->get();
        $total = count($allRows);
        $pages = (int)ceil(max(1, $total) / $limit);
        $offset = ($page - 1) * $limit;
        $rowsPage = collect($allRows)->slice($offset, $limit)->values();

        $presentRows = [];
        foreach ($rowsPage as $row) {
            $presentRows[] = [
                'popularity_rank' => (int)$row->popularity_rank,
                'template' => (string)$row->template,
                'min' => (int)($row->min_total_length ?? 0),
            ];
        }

        $meta = [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'count' => count($presentRows),
        ];

        return ['meta' => $meta, 'rows' => $presentRows];
    }

    public function listWithinMinLength(int $totalLength): Collection
    {
        return Pattern::where('min_total_length', '<=', $totalLength)
            ->orderBy('popularity_rank')
            ->get();
    }

    /**
     * Filter patterns for a given source by effective word match minimums.
     */
    public function filterPatternsForSource(
        string $sourceSignature,
        Collection $patterns,
        array $storedWordBasedMins,
        array $matchingWordBasedMins
    ): Collection
    {
        $sourceLength = strlen($sourceSignature);

        $tokenIdsByName = Token::all()->pluck('id', 'name')->toArray();

        return $patterns->filter(function ($row) use (
            $storedWordBasedMins,
            $matchingWordBasedMins,
            $sourceLength,
            $tokenIdsByName
        ) {
            $dynamicMin = 0;
            foreach ($tokenIdsByName as $id => $name) {
                if ($row->has($name)) {
                    // If this pattern requires a token for which no words were found, reject
                    if (!isset($matchedTokenIds, $id)) return false;
                    // Sum dynamic min only for tokens used by this pattern
                    $count = match ($name) {
                        Token::TOKEN_NAME_FORENAME => $row->forename_count,
                        Token::TOKEN_NAME_SURNAME => $row->surname_count,
                        default => 1,
                    };
                    $dynamicMin += max($matchingWordBasedMins[$id] ?? $storedWordBasedMins[$id]) * max(1, $count);
                }
            }

            return $dynamicMin <= $sourceLength;
        });
    }

}
