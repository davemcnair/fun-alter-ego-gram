<?php

namespace App\Console\Commands;

use App\Services\ListPatternsService;
use App\Traits\HelpsMatchWords;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:list:source', description: 'List stored patterns for a given source (always dynamic; no pagination or like filter)')]
class ListPatternsForSourceCommand extends Command
{
    use HelpsMatchWords;

    protected $signature = 'patterns:list:source {source*} {--list=} {--include-boring}';

    public function handle(ListPatternsService $svc): int
    {
        // Accept source as one or more words without needing quotes
        $sourceParts = (array) $this->argument('source');
        $source = trim(implode(' ', $sourceParts));
        if ($source === '') {
            $this->error('Please provide a source name, e.g. php artisan patterns:list:source "First Middle Last"');
            return self::FAILURE;
        }

        $signature = $this->makeSignature($source);
        $rows= $svc->listWithinMinLength(strlen($signature), 'standard');
        $rows = $svc->filterPatternsForSource($signature, $rows, (bool)$this->option('include-boring'));

        foreach ($rows as $row) {
            $this->line(sprintf('%5d. %s', $row['popularity_rank'], $row['template']));
        }
        return self::SUCCESS;
    }
}
