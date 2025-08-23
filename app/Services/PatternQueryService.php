<?php

namespace App\Services;

use App\Models\Token;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\DB;

class PatternQueryService
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

    /**
     * List patterns for a given source with dynamic/availability filtering options.
     *
     * @return array{
     *   meta: array{total:int, page:int, pages:int, count:int, source_len:int, mode?:string, list?:string, boring?:string},
     *   rows: array<int, array{popularity_rank:int, template:string, dyn_min?:int, avail?:bool, min?:int}>
     * }
     */
    public function listForSource(string $source, bool $includeBoring = false): array
    {
        $srcSig = $this->makeSignature($source);
        $srcLen = strlen($srcSig);

        $base = DB::table('words')->select('id', 'token_type', 'signature');

        if (!$includeBoring) $base->where('list_type', '!=', 'boring');
        $actualMins = Token::query()->pluck('min_length', 'name')->toArray();
        // can be larger
        $effectiveMins = $actualMins;
        $anyWordsFound = [];
        $base->chunkById(1000, function ($rows) use (&$effectiveMins, &$anyWordsFound, $srcSig, $srcLen) {
            foreach ($rows as $r) {
                $sig = $r->signature;
                $len = strlen($sig);
                if ($len > $srcLen) continue;
                if (!$this->isSubset($sig, $srcSig)) continue;
                $tok = $r->token_type;
                $anyWordsFound[$tok] = true;
                if ($len > $effectiveMins[$tok]) $effectiveMins[$tok] = $len;
            }
        }, 'id');

        $allRows = DB::table('patterns')
            ->where('min_total_length', '<=', $srcLen)
            ->orderBy('popularity_rank')
            ->get();

        $rows = $allRows->filter(function ($row) use ($actualMins, $effectiveMins, $anyWordsFound, $srcLen) {
            // Determine if the pattern row includes a given token type using stored columns
            $hasToken = function ($row, string $name): bool {
                return match ($name) {
                    Token::TOKEN_NAME_TITLE => (bool)($row->has_title ?? false),
                    Token::TOKEN_NAME_FORENAME => (int)($row->forename_count ?? 0) > 0,
                    Token::TOKEN_NAME_INITIALS => (bool)($row->has_initials ?? false),
                    Token::TOKEN_NAME_PREFIX => (bool)($row->has_prefix ?? false),
                    Token::TOKEN_NAME_SURNAME => (int)($row->surname_count ?? 0) > 0,
                    Token::TOKEN_NAME_SUFFIX => (bool)($row->has_suffix ?? false),
                    Token::TOKEN_NAME_HONORIFIC => (bool)($row->has_honorific ?? false),
                    default => false,
                };
            };

            $minLengthUnchanged = true;
            foreach (Token::NAMES as $name) {
                if ($hasToken($row, $name)) {
                    if (!isset($anyWordsFound[$name])) return false;
                    if (($effectiveMins[$name] ?? 0) > ($actualMins[$name] ?? 0)) {
                        $minLengthUnchanged = false;
                    }
                }
            }

            return $minLengthUnchanged || array_sum($effectiveMins) <= $srcLen;
        })->values();
        $presentRows=[];
        foreach ($rows as $row) {
            $presentRows[] = [
                'popularity_rank' => (int)$row->popularity_rank,
                'template' => (string)$row->template,
                'min' => $row->min_total_length
            ];
        }
        $meta = [
            'count' => count($presentRows),
            'source_len' => $srcLen,
        ];
        if (!$includeBoring) $meta['boring'] = 'excluded';

        return ['meta' => $meta, 'rows' => $presentRows];
    }
}
