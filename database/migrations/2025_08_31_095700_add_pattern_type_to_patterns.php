<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            // Add as non-null with default to avoid issues in existing rows
            if (!Schema::hasColumn('patterns', 'pattern_type')) {
                $table->string('pattern_type', 20)->default('exotic')->after('popularity_rank');
            }
        });
        // Backfill based on popularity_rank
        try {
            // First 9 ranks => standard
            DB::table('patterns')->where('popularity_rank', '<=', 9)->update(['pattern_type' => 'standard']);
            // Next 50 (10..59) => longer
            DB::table('patterns')->whereBetween('popularity_rank', [10, 59])->update(['pattern_type' => 'longer']);
            // Others remain exotic (default)
            DB::table('patterns')->where('popularity_rank', '>=', 60)->update(['pattern_type' => 'exotic']);
        } catch (\Throwable $e) {
            // Swallow in case table is empty during initial setups; structure still added.
        }
    }

    public function down(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            if (Schema::hasColumn('patterns', 'pattern_type')) {
                $table->dropColumn('pattern_type');
            }
        });
    }
};
