<?php

namespace App\Console\Commands;

use App\Services\PatternQueryService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:list:source', description: 'List stored patterns for a given source (always dynamic; no pagination or like filter)')]
class ListPatternsForSourceCommand extends Command
{
    protected $signature = 'patterns:list:source {source*} {--list=} {--include-boring}';

    public function handle(PatternQueryService $svc): int
    {
        // Accept source as one or more words without needing quotes
        $sourceParts = (array) $this->argument('source');
        $source = trim(implode(' ', $sourceParts));
        if ($source === '') {
            $this->error('Please provide a source name, e.g. php artisan patterns:list:source "First Middle Last"');
            return self::FAILURE;
        }

        $res = $svc->listForSource($source, (bool)$this->option('include-boring'));
        $meta = $res['meta'];
        $rows = $res['rows'];

        // Simplified header: no pagination/limit details
        $mode = isset($meta['mode']) ? ' ('.$meta['mode'].')' : '';
        $header = 'Total: '.$meta['total'].' | source_len='.$meta['source_len'].$mode;
        if (isset($meta['list'])) $header .= ' | list='.$meta['list'];
        elseif (($meta['boring'] ?? '') === 'excluded') $header .= ' | boring=excluded';
        $this->line($header);

        foreach ($rows as $row) {
            if (isset($row['dyn_min'])) {
                $this->line(sprintf('%5d. %s (dyn_min=%d)', $row['popularity_rank'], $row['template'], $row['dyn_min']));
            } elseif (($row['avail'] ?? false) === true) {
                $this->line(sprintf('%5d. %s (avail)', $row['popularity_rank'], $row['template']));
            } else {
                // Fallback; normally source route prints dyn_min or avail
                $this->line(sprintf('%5d. %s', $row['popularity_rank'], $row['template']));
            }
        }
        return self::SUCCESS;
    }
}
