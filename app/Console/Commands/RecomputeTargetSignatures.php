<?php

namespace App\Console\Commands;

use App\Models\Target;
use App\Support\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecomputeTargetSignatures extends Command
{
    protected $signature = 'targets:recompute-signatures {--dry-run : Do not write changes, only report}';
    protected $description = 'Recompute canonical signatures for all Targets; report duplicates and invalid rows.';

    public function handle(): int
    {
        $dry = (bool)$this->option('dry-run');
        $this->info('Recomputing target signatures' . ($dry ? ' (dry-run)' : ''));

        $bySig = [];
        $invalid = [];

        Target::orderBy('id')->chunkById(500, function($chunk) use (&$bySig, &$invalid) {
            foreach ($chunk as $t) {
                $sig = NameNormalizer::canonicalKey($t->name);
                if ($sig === '') {
                    $invalid[] = $t->id;
                    continue;
                }
                $bySig[$sig] = $bySig[$sig] ?? [];
                $bySig[$sig][] = $t->id;
            }
        });

        $dups = [];
        foreach ($bySig as $sig => $ids) {
            if (count($ids) > 1) {
                sort($ids);
                $keep = array_shift($ids); // keep lowest id
                $dups[] = ['sig' => $sig, 'keep' => $keep, 'dupes' => $ids];
            }
        }

        if ($dry) {
            $this->line('Invalid (empty after normalization): ' . implode(',', $invalid));
            foreach ($dups as $d) {
                $this->line('Duplicate signature ' . $d['sig'] . ' keep=' . $d['keep'] . ' dupes=[' . implode(',', $d['dupes']) . ']');
            }
            return Command::SUCCESS;
        }

        DB::transaction(function() use ($bySig, $invalid, $dups) {
            // Update valid signatures
            foreach ($bySig as $sig => $ids) {
                foreach ($ids as $id) {
                    /** @var Target $t */
                    $t = Target::find($id);
                    if (!$t) continue;
                    $t->signature = $sig; // unique key; may conflict if dupes exist, but we will update all rows first then delete dupes
                    $t->save();
                }
            }
            // Report invalids
            if (!empty($invalid)) {
                Log::warning('targets:recompute-signatures invalid_ids', ['ids' => $invalid]);
            }
            // Delete duplicate rows (keep lowest id)
            foreach ($dups as $d) {
                Log::warning('targets:recompute-signatures duplicate', $d);
                if (!empty($d['dupes'])) {
                    Target::whereIn('id', $d['dupes'])->delete();
                }
            }
        });

        $this->info('Done');
        return Command::SUCCESS;
    }
}
