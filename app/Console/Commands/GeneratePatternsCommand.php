<?php

namespace App\Console\Commands;

use App\Services\PatternCatalog;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'patterns:generate', description: 'Generate exhaustive name pattern templates honoring ordering and adjacency rules')]
class GeneratePatternsCommand extends Command
{
    protected $signature = 'patterns:generate {--dry-run} {--print=20}';

    public function handle(PatternCatalog $catalog): int
    {
        $dry = (bool) $this->option('dry-run');
        $printN = (int) $this->option('print');
        try {
            $res = $catalog->generate($dry);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($dry) {
            $this->info('Dry run: generated ' . count($res['list']) . ' patterns.');
        } else {
            $this->info('Stored ' . ($res['stored'] ?? 0) . ' patterns.');
        }

        if ($printN > 0) {
            $this->line('Top ' . $printN . ' patterns:');
            for ($i=0; $i < min($printN, count($res['list'])); $i++) {
                $row = $res['list'][$i];
                $this->line(sprintf('%4d. %s', $i+1, $row['template']));
            }
        }
        return self::SUCCESS;
    }
}
