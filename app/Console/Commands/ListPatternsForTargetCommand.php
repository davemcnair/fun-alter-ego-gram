<?php

namespace App\Console\Commands;

use App\Services\ListPatternsService;
use App\Traits\HelpsMatchWords;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:list:source', description: 'List stored patterns for a given source (always dynamic; no pagination or like filter)')]
class ListPatternsForTargetCommand extends Command
{
    use HelpsMatchWords;

    protected $signature = 'patterns:list:source {source*} {--list=} {--include-boring}';

    public function handle(ListPatternsService $svc): int
    {
        // Accept source as one or more words without needing quotes
        $targetParts = (array) $this->argument('source');
        $target = trim(implode(' ', $targetParts));
        if ($target === '') {
            $this->error('Please provide a source name, e.g. php artisan patterns:list:source "First Middle Last"');
            return self::FAILURE;
        }

        $signature = $this->makeSignature($target);
        $rows= $svc->listWithinMinLength(strlen($signature), 'standard');
        $rows = $svc->filterPatternsForTarget($signature, $rows, (bool)$this->option('include-boring'));

        foreach ($rows as $row) {
            $this->line(sprintf('%5d. %s', $row['popularity_rank'], $row['template']));
        }
        return self::SUCCESS;
    }
}
