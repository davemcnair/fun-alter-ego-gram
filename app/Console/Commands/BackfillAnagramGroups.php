<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\AnagramGroup;
use App\Models\Word;

class BackfillAnagramGroups extends Command
{
    protected $signature = 'words:backfill-anagram-groups';
    protected $description = 'Backfill anagram groups and assign words to groups based on token_type and signature';

    public function handle()
    {
        $this->info('Backfilling anagram groups...');
        $bar = $this->output->createProgressBar(0);
        $bar->start();

        DB::transaction(function () use ($bar) {
            // Build groups per token_type + signature
            $rows = DB::table('words')
                ->select('token_type', 'signature', DB::raw('COUNT(*) as c'))
                ->groupBy('token_type', 'signature')
                ->get();

            foreach ($rows as $row) {
                if (!$row->signature) continue;
                $group = AnagramGroup::firstOrCreate(
                    ['token_type' => (string)$row->token_type, 'signature' => (string)$row->signature],
                    ['words_count' => 0]
                );
                // Assign words missing group id
                $affected = Word::where('token_type', $row->token_type)
                    ->where('signature', $row->signature)
                    ->where(function($q){ $q->whereNull('anagram_group_id')->orWhere('anagram_group_id', 0); })
                    ->update(['anagram_group_id' => $group->id]);
                // Update count to actual number of words attached for this group
                $count = Word::where('anagram_group_id', $group->id)->count();
                $group->words_count = $count;
                $group->save();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Backfill complete.');
        return self::SUCCESS;
    }
}
