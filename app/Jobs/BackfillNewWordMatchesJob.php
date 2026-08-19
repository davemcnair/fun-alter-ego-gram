<?php

namespace App\Jobs;

use App\Models\Target;
use App\Models\TokenSignatureWord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Schema;

class BackfillNewWordMatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tokenSignatureWordId;

    public function __construct(int $tokenSignatureWordId)
    {
        $this->tokenSignatureWordId = $tokenSignatureWordId;
    }

    public function handle(): void
    {
        /** @var TokenSignatureWord|null $tsw */
        $tsw = TokenSignatureWord::find($this->tokenSignatureWordId);
        if (!$tsw) return;
        if ($tsw->is_deferred) return; // skip deferred
        if (!in_array($tsw->list_type, ['fun','ok'], true)) return; // skip boring

        $sig = $tsw->tokenSignature->signature;

        // Build target query with SQL-side pruning using letter count columns when present
        $query = Target::query()
            ->whereHas('signature', function ($q) use ($sig) {
                // Ensure targets are at least as long as the word's signature
                $q->where('length', '>=', (int)($sig->length ?? 0));
                // For each letter a-z, target letterCounts must be >= the word's signature letterCounts
                foreach (range('a', 'z') as $ch) {
                    $n = (int)($sig->{$ch . '_count'} ?? 0);
                    if ($n > 0) {
                        $q->where($ch . '_count', '>=', $n);
                    }
                }
            });

        $count = 0;
        $query->orderBy('id')->chunkById(1000, function($targets) use ($tsw, &$count) {
            $rows = [];
            foreach ($targets as $t) {
                $rows[] = [
                    'target_id' => (int)$t->id,
                    'token_signature_word_id' => (int)$tsw->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if (!empty($rows)) {
                // Use insertOrIgnore to avoid violating the unique pivot constraint if rows already exist.
                DB::table('target_token_signature_words')->insertOrIgnore($rows);
                $count += count($rows);
            }
        });

        try { Log::info('BackfillNewWordMatchesJob: word_id='.$tsw->id.' matches_count='.$count); } catch (\Throwable $e) {}
    }
}
