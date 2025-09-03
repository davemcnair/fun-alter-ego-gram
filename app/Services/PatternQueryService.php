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
        $sourceSignature = $this->makeSignature($source);
        $sourceLength = strlen($sourceSignature);

        $wordsQuery = DB::table('words')->select('id', 'token_type', 'signature');

        if (!$includeBoring) {
            $wordsQuery->where('list_type', '!=', 'boring');
        }
        $actualMins = Token::query()->pluck('min_length', 'name')->toArray();
        // can be larger
        $effectiveMins = [];
        $anyWordsFound = [];
        $wordsQuery->chunkById(1000, function ($rows) use (&$effectiveMins, &$anyWordsFound, $sourceSignature, $sourceLength) {
            foreach ($rows as $r) {
                $signature = $r->signature;
                $length = strlen($signature);
                if ($length > $sourceLength) continue;
                if (!$this->isSubset($signature, $sourceSignature)) continue;
                $token_type = $r->token_type;
                $anyWordsFound[$token_type] = true;
                if (!isset($effectiveMins[$token_type]) || $length < $effectiveMins[$token_type]) {
                    $effectiveMins[$token_type] = $length;
                }
            }
        }, 'id');

        $allRows = DB::table('patterns')
            ->where('min_total_length', '<=', $sourceLength)
            ->orderBy('popularity_rank')
            ->get();

        // If we found no matching words for any token at all, fall back to static filtering only
        $foundAnyWords = !empty($anyWordsFound);

        $filtered = $allRows->filter(function ($row) use ($actualMins, $effectiveMins, $anyWordsFound, $sourceLength, $foundAnyWords) {
            if (!$foundAnyWords) {
                // Static prefilter (min_total_length) was already applied in the query; accept row
                return true;
            }

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
            $dynMin = 0;
            foreach (Token::NAMES as $name) {
                if ($hasToken($row, $name)) {
                    // If this pattern requires a token for which no words were found, reject
                    if (!isset($anyWordsFound[$name])) return false;
                    // Track if any effective min grew compared to static
                    if (($effectiveMins[$name] ?? 0) > ($actualMins[$name] ?? 0)) {
                        $minLengthUnchanged = false;
                    }
                    // Sum dynamic min only for tokens used by this pattern
                    $count = match ($name) {
                        Token::TOKEN_NAME_FORENAME => (int)($row->forename_count ?? 0),
                        Token::TOKEN_NAME_SURNAME => (int)($row->surname_count ?? 0),
                        default => 1,
                    };
                    $dynMin += (int)($effectiveMins[$name] ?? 0) * max(1, $count);
                }
            }

            // Attach markers on the row for later mapping
            $row->dyn_min_marker = $minLengthUnchanged ? null : $dynMin;
            $row->min_unchanged_marker = $minLengthUnchanged;

            return $minLengthUnchanged || $dynMin <= $sourceLength;
        })->values();

        // Map to plain associative arrays as the public API for downstream code
        $out = [];
        foreach ($filtered as $r) {
            $item = [
                'popularity_rank' => (int)$r->popularity_rank,
                'template' => (string)$r->template,
                'min' => (int)($r->min_total_length ?? 0),
            ];
            if (isset($r->dyn_min_marker) && $r->dyn_min_marker !== null) {
                $item['dyn_min'] = (int)$r->dyn_min_marker;
            } else {
                // if unchanged but we want to signal availability, include avail flag
                $item['avail'] = true;
            }
            $out[] = $item;
        }

        return $out;
    }
}
