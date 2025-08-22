<?php

namespace App\Console\Commands;

use App\Services\TokenWordsBuilderService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'token_words:build', description: 'Build token_words files from altego sources')]
class BuildTokenWordsCommand extends Command
{
    protected $signature = 'token_words:build {--save} {--dest=}';

    public function handle(TokenWordsBuilderService $svc): int
    {
        $save = (bool)$this->option('save') || !is_null($this->option('dest'));
        $dest = (string)($this->option('dest') ?? '');
        try {
            $built = $svc->build($save, $dest === '' ? null : $dest);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        foreach ($built as $b) {
            $group = $b['group'];
            $msg = "Built $group:";
            if (isset($b['fun'])) $msg .= " fun.txt (".$b['fun'].")";
            if (isset($b['ok'])) $msg .= ", ok.txt (".$b['ok'].")";
            if (isset($b['boring'])) $msg .= ", boring.txt (".$b['boring'].")";
            $this->info($msg);
        }
        if ($save) {
            $destArg = (string)($this->option('dest') ?? '');
            $destRel = $destArg !== '' ? $destArg : 'resources/token_words';
            $this->info('Saved a repo copy to: ' . base_path($destRel));
        }
        $this->info('token_words build complete.');
        return self::SUCCESS;
    }
}
