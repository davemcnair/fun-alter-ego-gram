<?php

namespace App\Console\Commands;

use App\Services\PatternQueryService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:list', description: 'List stored patterns with optional filtering and pagination (supports --source and dynamic min filtering)')]
class ListPatternsCommand extends Command
{
    protected $signature = 'patterns:list {--limit=20} {--page=1} {--like=} {--source=} {--dynamic} {--list=} {--include-boring} {--filter-empty-only}';

    public function handle(PatternQueryService $svc): int
    {
        $opts = [
            'limit' => (int)$this->option('limit'),
            'page' => (int)$this->option('page'),
            'like' => (string)$this->option('like'),
            'source' => (string)$this->option('source'),
            'dynamic' => (bool)$this->option('dynamic'),
            'list' => (string)$this->option('list'),
            'include_boring' => (bool)$this->option('include-boring'),
            'filter_empty_only' => (bool)$this->option('filter-empty-only'),
        ];

        $res = $svc->list($opts);
        $meta = $res['meta'];
        $rows = $res['rows'];

        $header = 'Total: '.$meta['total'].' | Page '.$meta['page'].' of '.$meta['pages'].' | Showing '.$meta['count'].' (limit '.$opts['limit'].')';
        if (isset($meta['source_len'])) {
            $mode = isset($meta['mode']) ? ' ('.$meta['mode'].')' : '';
            $header .= ' | source_len='.$meta['source_len'].$mode;
            if (isset($meta['list'])) $header .= ' | list='.$meta['list'];
            elseif (($meta['boring'] ?? '') === 'excluded') $header .= ' | boring=excluded';
        }
        $this->line($header);

        foreach ($rows as $row) {
            if (isset($row['dyn_min'])) {
                $this->line(sprintf('%5d. %s (dyn_min=%d)', $row['popularity_rank'], $row['template'], $row['dyn_min']));
            } elseif (($row['avail'] ?? false) === true) {
                $this->line(sprintf('%5d. %s (avail)', $row['popularity_rank'], $row['template']));
            } else {
                $this->line(sprintf('%5d. %s (min=%d)', $row['popularity_rank'], $row['template'], $row['min'] ?? 0));
            }
        }
        return self::SUCCESS;
    }
}
