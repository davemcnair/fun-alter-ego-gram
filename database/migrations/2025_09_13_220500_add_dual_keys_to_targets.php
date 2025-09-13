<?php

use App\Models\Target;
use App\Support\NameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            // Add columns first; we'll backfill then add constraints
            if (!Schema::hasColumn('targets', 'normalized_key')) {
                $table->string('normalized_key')->default('');
            }
            if (!Schema::hasColumn('targets', 'anagram_signature')) {
                $table->string('anagram_signature')->nullable();
                $table->index('anagram_signature');
            }
        });

        // Backfill existing rows
        if (Schema::hasTable('targets')) {
            // Use a transaction to keep state consistent
            DB::beginTransaction();
            try {
                $rows = DB::table('targets')->select('id', 'name')->orderBy('id')->get();
                foreach ($rows as $row) {
                    $normalized = NameNormalizer::canonicalKey($row->name ?? '');
                    if ($normalized === '') {
                        Log::warning('Backfill skipped: empty normalized_key', [
                            'target_id' => $row->id,
                        ]);
                        continue;
                    }
                    $anagram = NameNormalizer::anagramSignature($row->name ?? '');
                    DB::table('targets')->where('id', $row->id)->update([
                        'normalized_key' => $normalized,
                        'anagram_signature' => $anagram,
                    ]);
                }

                // Resolve duplicates by normalized_key: keep lowest id
                $dupes = DB::table('targets')
                    ->select('normalized_key', DB::raw('count(*) as n'))
                    ->where('normalized_key', '!=', '')
                    ->groupBy('normalized_key')
                    ->having('n', '>', 1)
                    ->pluck('normalized_key');

                foreach ($dupes as $nk) {
                    $ids = DB::table('targets')->where('normalized_key', $nk)->orderBy('id')->pluck('id');
                    $keep = $ids->first();
                    $drop = $ids->slice(1)->values();
                    if ($drop->isNotEmpty()) {
                        Log::warning('Backfill duplicate normalized_key; keeping lowest id', [
                            'normalized_key' => $nk,
                            'keep_id' => $keep,
                            'drop_ids' => $drop->all(),
                        ]);
                        // Deleting duplicates; we do not merge related data in this change.
                        DB::table('targets')->whereIn('id', $drop)->delete();
                    }
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        // Add unique constraint and not-null after backfill
        Schema::table('targets', function (Blueprint $table) {
            // Unique index
            $table->unique('normalized_key');
        });

        // Make normalized_key NOT NULL (default '' already enforces non-null). In databases that
        // support it, drop the default and keep NOT NULL; SQLite will ignore.
        try {
            Schema::table('targets', function (Blueprint $table) {
                $table->string('normalized_key')->default(null)->change();
            });
        } catch (\Throwable $e) {
            // ignore if platform doesn't support change()
        }
    }

    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            try { $table->dropUnique(['normalized_key']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['anagram_signature']); } catch (\Throwable $e) {}
            if (Schema::hasColumn('targets', 'normalized_key')) {
                $table->dropColumn('normalized_key');
            }
            if (Schema::hasColumn('targets', 'anagram_signature')) {
                $table->dropColumn('anagram_signature');
            }
        });
    }
};
