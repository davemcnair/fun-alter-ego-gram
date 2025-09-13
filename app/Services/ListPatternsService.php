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
    public function filterPatternsForTarget(
        string $targetSignature,
        Collection $patterns,
        array $storedWordBasedMins,
        array $matchingWordBasedMins
    ): Collection
    {
        $targetLength = strlen($targetSignature);

        $tokenIdsByName = Token::all()->pluck('id', 'name')->toArray();

        return $patterns->filter(function ($row) use (
            $storedWordBasedMins,
            $matchingWordBasedMins,
            $targetLength,
            $tokenIdsByName
        ) {
            $dynamicMin = 0;
            foreach ($tokenIdsByName as $name => $id) {
                if ($row->has($name)) {
                    // Require at least one matched word for any token used by this pattern
                    if (!isset($matchingWordBasedMins[$id])) {
                        return false;
                    }
                    // Sum dynamic min only for tokens used by this pattern
                    $count = match ($name) {
                        Token::TOKEN_NAME_FORENAME => (int)$row->forename_count,
                        Token::TOKEN_NAME_SURNAME => (int)$row->surname_count,
                        default => 1,
                    };
                    $count = max(1, $count);
                    $stored = (int)($storedWordBasedMins[$id] ?? 0);
                    $matched = (int)$matchingWordBasedMins[$id];
                    $effectiveMin = max($stored, $matched);
                    $dynamicMin += $effectiveMin * $count;
                    if ($dynamicMin > $targetLength) {
                        return false; // early exit if already exceeds source length
                    }
                }
            }

            return $dynamicMin <= $targetLength;
        });
    }

}
