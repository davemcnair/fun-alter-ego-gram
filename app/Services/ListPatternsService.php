<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ListPatternsService
{

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

}
