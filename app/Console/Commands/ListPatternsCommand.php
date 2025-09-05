<?php

namespace App\Console\Commands;

use App\Services\ListPatternsService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:list', description: 'List stored patterns with optional filtering and pagination')]
class ListPatternsCommand extends Command
{
    protected $signature = 'patterns:list {--limit=20} {--page=1} {--like=}';

    public function handle(ListPatternsService $svc): int
    {
        $opts = [
            'limit' => (int)$this->option('limit'),
            'page' => (int)$this->option('page'),
            'like' => (string)$this->option('like'),
        ];

        $res = $svc->list($opts);

        $meta = $res['meta'];
        $rows = $res['rows'];

        $header = 'Total: '.$meta['total'].' | Page '.$meta['page'].' of '.$meta['pages'].' | Showing '.$meta['count'].' (limit '.$opts['limit'].')';
        $this->line($header);

        foreach ($rows as $row) {
            $this->line(sprintf('%5d. %s (min=%d)', $row['popularity_rank'], $row['template'], $row['min'] ?? 0));
        }
        return self::SUCCESS;
    }
}
