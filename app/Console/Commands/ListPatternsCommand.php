<?php

namespace App\Console\Commands;

use App\Dtos\PatternCatalogPageQuery;
use App\Services\PatternCatalog;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:list', description: 'List stored patterns with optional filtering and pagination')]
class ListPatternsCommand extends Command
{
    protected $signature = 'patterns:list {--limit=20} {--page=1} {--like=}';

    public function handle(PatternCatalog $catalog): int
    {
        $snapshot = $catalog->listPage(new PatternCatalogPageQuery(
            like: (string) $this->option('like'),
            perPage: (int) $this->option('limit'),
            page: (int) $this->option('page'),
        ));
        $items = $snapshot->items;

        $header = 'Total: '.$items->total().' | Page '.$items->currentPage().' of '.$items->lastPage().' | Showing '.$items->count().' (limit '.$items->perPage().')';
        $this->line($header);

        foreach ($items as $row) {
            $this->line(sprintf('%5d. %s (min=%d)', $row->rank, $row->template, $row->minLength));
        }

        return self::SUCCESS;
    }
}
