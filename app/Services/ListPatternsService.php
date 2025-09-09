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
        $anyWordsFound = array_keys($matchingWordBasedMins);

        return $patterns->filter(function ($row) use ($storedWordBasedMins, $matchingWordBasedMins, $anyWordsFound, $sourceLength) {
            // Determine if the pattern row includes a given token type using stored columns
            $hasToken = function ($row, $tokenName): bool {
                return match ($tokenName) {
                    Token::TOKEN_NAME_TITLE => $row->has_title ?? false,
                    Token::TOKEN_NAME_FORENAME => ($row->forename_count ?? 0) > 0,
                    Token::TOKEN_NAME_INITIALS => $row->has_initials ?? false,
                    Token::TOKEN_NAME_PREFIX => $row->has_prefix ?? false,
                    Token::TOKEN_NAME_SURNAME => ($row->surname_count ?? 0) > 0,
                    Token::TOKEN_NAME_SUFFIX => $row->has_suffix ?? false,
                    Token::TOKEN_NAME_HONORIFIC => $row->has_honorific ?? false,
                    default => false,
                };
            };

            $minLengthUnchanged = true;
            $dynamicMin = 0;
            foreach (Token::NAMES as $name) {
                if ($hasToken($row, $name)) {
                    // If this pattern requires a token for which no words were found, reject
                    if (!isset($anyWordsFound[$name])) return false;
                    // Track if any effective min grew compared to static
                    if (($matchingWordBasedMins[$name] ?? 0) > ($storedWordBasedMins[$name] ?? 0)) {
                        $minLengthUnchanged = false;
                    }
                    // Sum dynamic min only for tokens used by this pattern
                    $count = match ($name) {
                        Token::TOKEN_NAME_FORENAME => (int)($row->forename_count ?? 0),
                        Token::TOKEN_NAME_SURNAME => (int)($row->surname_count ?? 0),
                        default => 1,
                    };
                    $dynamicMin += (int)($matchingWordBasedMins[$name] ?? 0) * max(1, $count);
                }
            }

            return $minLengthUnchanged || $dynamicMin <= $sourceLength;
        });
    }

}
