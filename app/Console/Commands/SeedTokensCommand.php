<?php

namespace App\Console\Commands;

use App\Services\TokenSeedService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'tokens:seed', description: 'Seed tokens based on resources/token_words')]
class SeedTokensCommand extends Command
{
    protected $signature = 'tokens:seed';

    public function handle(TokenSeedService $svc): int
    {
        try {
            $summary = $svc->seedFromResources(base_path('resources/token_words'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        foreach ($summary as $s) {
            $this->info(sprintf(
                'Seeded token: %s (prio=%d, min=%d, fun=%s, boring=%s, maxMultiples=%d)',
                $s['name'], $s['prio'], $s['minLen'], $s['hasFun'] ? 'Y' : 'N', $s['hasBoring'] ? 'Y' : 'N', $s['maxMultiples']
            ));
        }
        $this->info('Seeded ' . count($summary) . ' tokens from resources/token_words.');
        return self::SUCCESS;
    }
}
