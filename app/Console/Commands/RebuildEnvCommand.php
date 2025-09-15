<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;

#[AsCommand(name: 'app:rebuild-env', description: 'Rebuild local/dev environment: migrate:fresh, seed, and optionally run replay routines.\n\nUsage examples:\n  php artisan app:rebuild-env\n  php artisan app:rebuild-env --no-seed\n  php artisan app:rebuild-env --with-replays\n  php artisan app:rebuild-env --only-replays\n  php artisan app:rebuild-env --force')]
class RebuildEnvCommand extends Command
{
    protected $signature = 'app:rebuild-env '
        . '{--no-seed : Skip database seeders} '
        . '{--with-replays : Run replay routines after seeding} '
        . '{--only-replays : Only run replay routines (no migrate/seed)} '
        . '{--force : Force run even in production environments}';

    public function handle(): int
    {
        $noSeed = (bool) $this->option('no-seed');
        $withReplays = (bool) $this->option('with-replays');
        $onlyReplays = (bool) $this->option('only-replays');
        $force = (bool) $this->option('force');

        // Safety guard for production/CI
        $isProduction = App::environment('production') || (config('app.env') === 'production');
        if ($isProduction && !$force) {
            $this->error('Refusing to run in production without --force.');
            return self::FAILURE;
        }

        // If not only-replays, perform destructive rebuild steps
        if (!$onlyReplays) {
            $this->info('Clearing caches...');
            if ($this->callAndReport('cache:clear') !== 0) {
                return self::FAILURE;
            }
            if ($this->callAndReport('config:clear') !== 0) {
                return self::FAILURE;
            }

            $this->info('Refreshing database (migrate:fresh)...');
            if ($this->callAndReport('migrate:fresh', ['--force' => true]) !== 0) {
                return self::FAILURE;
            }

            if (!$noSeed) {
                $this->info('Seeding database (db:seed)...');
                if ($this->callAndReport('db:seed', ['--force' => true]) !== 0) {
                    return self::FAILURE;
                }
            } else {
                $this->line('Skipping seed as requested (--no-seed).');
            }
        } else {
            $this->line('Only replays requested; skipping cache clear, migrate and seed.');
        }

        // Run optional replay routines
        if ($withReplays || $onlyReplays) {
            $this->info('Running replay routines...');
            if ($this->maybeCallReplay('replay-words-added') !== 0) {
                return self::FAILURE;
            }
            if ($this->maybeCallReplay('replay-word-list-type-changed') !== 0) {
                return self::FAILURE;
            }
        }

        $this->info('Environment rebuild completed successfully.');
        return self::SUCCESS;
    }

    /**
     * Call an Artisan command and stream its result to the console.
     */
    protected function callAndReport(string $command, array $parameters = []): int
    {
        $this->line(sprintf('> php artisan %s%s', $command, $parameters ? ' ' . $this->formatParamsForEcho($parameters) : ''));
        $code = Artisan::call($command, $parameters);
        $output = trim(Artisan::output());
        if ($output !== '') {
            $this->line($output);
        }
        if ($code !== 0) {
            $this->error(sprintf('Command "%s" failed with exit code %d.', $command, $code));
        }
        return (int) $code;
    }

    /**
     * Conditionally call a replay command if it is registered; skip gracefully otherwise.
     */
    protected function maybeCallReplay(string $command): int
    {
        if (!Artisan::has($command)) {
            $this->line(sprintf('Replay "%s" not registered; skipping.', $command));
            return 0;
        }
        $this->line(sprintf('Executing replay: %s', $command));
        return $this->callAndReport($command);
    }

    protected function formatParamsForEcho(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                if ($v) $parts[] = $k; // e.g., --force
            } else {
                $parts[] = $k . '=' . (string) $v;
            }
        }
        return implode(' ', $parts);
    }
}
