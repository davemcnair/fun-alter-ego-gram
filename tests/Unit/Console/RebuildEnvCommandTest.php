<?php

namespace Tests\Unit\Console;

use App\Console\Commands\RebuildEnvCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class RebuildEnvCommandTest extends TestCase
{
    private function runRebuild(array $options = []): array
    {
        $command = app(RebuildEnvCommand::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput($options);
        $output = new BufferedOutput();
        $exit = $command->run($input, $output);
        return [$exit, $output->fetch()];
    }
    public function test_refuses_in_production_without_force(): void
    {
        config(['app.env' => 'production']);

        // Call the command directly via Artisan to capture exit code reliably in this environment
        $exit = Artisan::call('app:rebuild-env');
        $this->assertSame(1, $exit);
    }

    public function test_default_runs_migrate_and_seed(): void
    {
        config(['app.env' => 'local']);

        // cache:clear, config:clear, migrate:fresh --force, db:seed --force
        Artisan::shouldReceive('call')->once()->with('cache:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('config:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('db:seed', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        [$exit, $out] = $this->runRebuild();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Environment rebuild completed successfully.', $out);
    }

    public function test_no_seed_option_skips_seeding(): void
    {
        config(['app.env' => 'local']);

        Artisan::shouldReceive('call')->once()->with('cache:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('config:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        // Ensure db:seed is not called

        [$exit, $out] = $this->runRebuild(['--no-seed' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Skipping seed as requested (--no-seed).', $out);
    }

    public function test_only_replays_runs_no_migrate_or_seed(): void
    {
        config(['app.env' => 'local']);

        // Check replays are skipped when not registered
        Artisan::shouldReceive('has')->with('replay-words-added')->andReturn(false);
        Artisan::shouldReceive('has')->with('replay-word-list-type-changed')->andReturn(false);

        [$exit, $out] = $this->runRebuild(['--only-replays' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Only replays requested; skipping cache clear, migrate and seed.', $out);
        $this->assertStringContainsString('Replay "replay-words-added" not registered; skipping.', $out);
        $this->assertStringContainsString('Replay "replay-word-list-type-changed" not registered; skipping.', $out);
    }

    public function test_with_replays_runs_present_ones_and_skips_missing(): void
    {
        config(['app.env' => 'local']);

        // Normal path calls
        Artisan::shouldReceive('call')->once()->with('cache:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('config:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('db:seed', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        // Replays: first missing, second present
        Artisan::shouldReceive('has')->with('replay-words-added')->andReturn(false);
        Artisan::shouldReceive('has')->with('replay-word-list-type-changed')->andReturn(true);
        Artisan::shouldReceive('call')->once()->with('replay-word-list-type-changed', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        [$exit, $out] = $this->runRebuild(['--with-replays' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replay "replay-words-added" not registered; skipping.', $out);
        $this->assertStringContainsString('Executing replay: replay-word-list-type-changed', $out);
    }

    public function test_non_zero_exit_when_subcommand_fails(): void
    {
        config(['app.env' => 'local']);

        Artisan::shouldReceive('call')->once()->with('cache:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('config:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(1);
        Artisan::shouldReceive('output')->andReturn('');

        [$exit, $out] = $this->runRebuild();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('failed with exit code 1', $out);
    }
}
