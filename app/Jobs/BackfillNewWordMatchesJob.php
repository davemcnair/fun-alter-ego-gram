<?php

namespace App\Jobs;

use App\Models\Target;
use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillNewWordMatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HelpsMatchWords;

    public int $tokenSignatureWordId;

    public function __construct(int $tokenSignatureWordId)
    {
        $this->tokenSignatureWordId = $tokenSignatureWordId;
    }

    public function handle(): void
    {
        /** @var TokenSignatureWord|null $tsw */
        $tsw = TokenSignatureWord::with('tokenSignature')->find($this->tokenSignatureWordId);
        if (!$tsw) return;
        if ($tsw->is_deferred) return; // skip deferred
        if (!in_array($tsw->list_type, ['fun','ok'], true)) return; // skip boring

        $sig = (string) optional($tsw->tokenSignature)->signature;
        if ($sig === '') return;

        $hist = $this->letterCountsFromSignature($sig);
        $len = strlen($sig);

        // Build target query with SQL-side pruning using letter count columns when present
        $query = Target::query();
        // Prefer modern columns if present
        $hasLen = \Schema::hasColumn('targets', 'signature_length');
        $hasCounts = \Schema::hasColumn('targets', 'a_count');
        if ($hasLen) {
            $query->where('signature_length', '>=', $len);
        }
        // Fallback to legacy check on signature string length
        if (!$hasLen) {
            $query->whereRaw('LENGTH(signature) >= ?', [$len]);
        }
        if ($hasCounts) {
            foreach (range('a','z') as $ch) {
                $n = (int)($hist[$ch] ?? 0);
                if ($n > 0) {
                    $query->where($ch . '_count', '>=', $n);
                }
            }
        }

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
                // This preserves existing is_new values and only inserts when absent.
                DB::table('target_token_signature_words')->insertOrIgnore($rows);
                $count += count($rows);
            }
        });

        try { Log::info('BackfillNewWordMatchesJob: word_id='.$tsw->id.' matches_count='.$count); } catch (\Throwable $e) {}
    }
}
