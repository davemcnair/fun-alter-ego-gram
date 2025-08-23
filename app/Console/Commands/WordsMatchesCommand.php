<?php

namespace App\Console\Commands;

use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'words:matches', description: 'Find all token word matches whose letters fit within the given source name')]
class WordsMatchesCommand extends Command
{
    use HelpsMatchWords;

    protected $signature = 'words:matches {source*} {--token=} {--list=} {--json} {--include-boring}';

    public function handle(WordMatchService $svc): int
    {
        $sourceParts = (array) $this->argument('source');
        $sourceName = trim(implode(' ', $sourceParts));
        if ($sourceName === '') {
            $this->error('Please provide a source name, e.g. php artisan words:matches "First Middle Last"');
            return self::FAILURE;
        }
        $sourceSignature = $this->makeSignature($sourceName);
        $payload = $svc->findMatches($sourceSignature, [
            'token' => (string)$this->option('token'),
            'list' => (string)$this->option('list'),
            'include_boring' => (bool)$this->option('include-boring'),
        ]);

        $asJson = (bool)$this->option('json');
        if ($asJson) {
            $this->line(json_encode([
                'source' => $payload['source'],
                'signature' => $payload['signature'],
                'total_matches' => $payload['total'],
                'groups' => $payload['groups'],
            ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Source: ' . $payload['source'] . ' | signature=' . $payload['signature'] . ' | total matches=' . $payload['total']);
        if ($payload['total'] === 0) return self::SUCCESS;

        $grouped = $payload['groups'];
        $sampleLimit = 10;
        ksort($grouped, SORT_STRING);
        foreach ($grouped as $token => $byList) {
            $this->line('[' . $token . ']');
            ksort($byList, SORT_STRING);
            foreach ($byList as $listType => $items) {
                $count = count($items);
                $this->line(sprintf('  - %s: %d', $listType, $count));
                $show = array_slice($items, 0, $sampleLimit);
                foreach ($show as $it) {
                    $this->line('      • ' . $it['word'] . ' (' . $it['signature'] . ')');
                }
                if ($count > $sampleLimit) {
                    $this->line('      ... and ' . ($count - $sampleLimit) . ' more');
                }
            }
        }
        return self::SUCCESS;
    }
}
