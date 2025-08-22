<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PatternQueryService
{
    public function __construct(private readonly TextSignatureService $sig)
    {
    }

    /**
     * Query patterns with optional dynamic/availability filtering.
     * Returns header meta and rows for presentation.
     *
     * @param array{
     *   like?:string, source?:string, dynamic?:bool, list?:string, include_boring?:bool, filter_empty_only?:bool,
     *   limit?:int, page?:int
     * } $opts
     * @return array{
     *   meta: array{total:int, page:int, pages:int, count:int, source_len?:int, mode?:string, list?:string, boring?:string},
     *   rows: array<int, array{popularity_rank:int, template:string, min?:int, dyn_min?:int, avail?:bool}>
     * }
     */
    public function list(array $opts): array
    {
        $limit = max(1, (int)($opts['limit'] ?? 20));
        $page  = max(1, (int)($opts['page'] ?? 1));
        $like  = (string)($opts['like'] ?? '');
        $source = (string)($opts['source'] ?? '');
        $useDynamic = (bool)($opts['dynamic'] ?? false);
        $filterList = (string)($opts['list'] ?? '');
        $includeBoring = (bool)($opts['include_boring'] ?? false);
        $filterEmptyOnly = (bool)($opts['filter_empty_only'] ?? false);

        $srcSig = '';
        $srcLen = null;
        if ($source !== '') { $srcSig = $this->sig->makeSignature($source); $srcLen = strlen($srcSig); }

        $effectiveMin = [];
        $tokenNames = ['title','forename','initials','prefix','surname','suffix','honorific'];
        $usedDynamic = false;
        if (($useDynamic || $filterEmptyOnly) && $srcLen !== null && $srcLen > 0) {
            $usedDynamic = true;
            $base = DB::table('words')->select('id','token_type','signature');
            if ($filterList !== '') {
                $base->where('list_type', $filterList);
            } else {
                if (!$includeBoring) $base->where('list_type', '!=', 'boring');
            }
            $mins = [];
            $base->orderBy('id');
            $sigSvc = $this->sig;
            $base->chunkById(1000, function ($rows) use (&$mins, $srcSig, $srcLen, $sigSvc) {
                foreach ($rows as $r) {
                    $sig = (string)($r->signature ?? '');
                    $len = strlen($sig);
                    if ($srcLen !== null && $len > $srcLen) continue;
                    if (!$sigSvc->isSubset($sig, $srcSig)) continue;
                    $tok = (string)$r->token_type;
                    if (!isset($mins[$tok]) || $len < $mins[$tok]) $mins[$tok] = $len;
                }
            }, 'id');
            foreach ($tokenNames as $tn) $effectiveMin[$tn] = $mins[$tn] ?? null;
        }

        $query = DB::table('patterns')->orderBy('popularity_rank');
        if ($like !== '') $query->where('template', 'like', '%' . $like . '%');
        if ($srcLen !== null) {
            if (!$usedDynamic || $filterEmptyOnly) $query->where('min_total_length', '<=', $srcLen);
        }
        $allRows = $query->get();

        $rows = $allRows;
        if ($usedDynamic) {
            $rows = $allRows->filter(function ($row) use ($effectiveMin, $srcLen, $filterEmptyOnly) {
                $min = 0;
                if ($row->has_title) { if ($effectiveMin['title'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['title']; }
                if (($row->forename_count ?? 0) > 0) { if ($effectiveMin['forename'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['forename'] * (int)$row->forename_count; }
                if ($row->has_initials) { if ($effectiveMin['initials'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['initials']; }
                if ($row->has_prefix) { if ($effectiveMin['prefix'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['prefix']; }
                if (($row->surname_count ?? 0) > 0) { if ($effectiveMin['surname'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['surname'] * (int)$row->surname_count; }
                if ($row->has_suffix) { if ($effectiveMin['suffix'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['suffix']; }
                if ($row->has_honorific) { if ($effectiveMin['honorific'] === null) return false; if (!$filterEmptyOnly) $min += $effectiveMin['honorific']; }
                if ($filterEmptyOnly) return true;
                return $srcLen === null ? true : ($min <= $srcLen);
            })->values();
        }

        $total = count($rows);
        $pages = (int) ceil(max(1, $total) / $limit);
        $offset = ($page - 1) * $limit;
        $rowsPage = collect($rows)->slice($offset, $limit)->values();

        $mode = null;
        if ($srcLen !== null) {
            $mode = $usedDynamic ? ($filterEmptyOnly ? 'avail-only' : 'dynamic') : null;
        }

        $presentRows = [];
        foreach ($rowsPage as $row) {
            if ($usedDynamic) {
                if ($filterEmptyOnly) {
                    $presentRows[] = [
                        'popularity_rank' => (int)$row->popularity_rank,
                        'template' => (string)$row->template,
                        'avail' => true,
                    ];
                } else {
                    $min = 0;
                    if ($row->has_title) $min += $effectiveMin['title'] ?? 0;
                    if (($row->forename_count ?? 0) > 0) $min += ($effectiveMin['forename'] ?? 0) * (int)$row->forename_count;
                    if ($row->has_initials) $min += $effectiveMin['initials'] ?? 0;
                    if ($row->has_prefix) $min += $effectiveMin['prefix'] ?? 0;
                    if (($row->surname_count ?? 0) > 0) $min += ($effectiveMin['surname'] ?? 0) * (int)$row->surname_count;
                    if ($row->has_suffix) $min += $effectiveMin['suffix'] ?? 0;
                    if ($row->has_honorific) $min += $effectiveMin['honorific'] ?? 0;
                    $presentRows[] = [
                        'popularity_rank' => (int)$row->popularity_rank,
                        'template' => (string)$row->template,
                        'dyn_min' => $min,
                    ];
                }
            } else {
                $presentRows[] = [
                    'popularity_rank' => (int)$row->popularity_rank,
                    'template' => (string)$row->template,
                    'min' => (int)($row->min_total_length ?? 0),
                ];
            }
        }

        $meta = [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'count' => count($presentRows),
        ];
        if ($srcLen !== null) {
            $meta['source_len'] = $srcLen;
            if ($mode) $meta['mode'] = $mode;
            if ($filterList !== '') $meta['list'] = $filterList;
            elseif (!$includeBoring) $meta['boring'] = 'excluded';
        }

        return [ 'meta' => $meta, 'rows' => $presentRows ];
    }
}
