<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_names', function (Blueprint $table) {
            $columns = [
                'patterns_total','patterns_searched','alteregos_found',
                'current_pattern','elapsed_seconds','started_at','paused_at','completed_at'
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('source_names', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('source_names', function (Blueprint $table) {
            // Best-effort recreate with defaults; may not match prior values
            if (!Schema::hasColumn('source_names', 'patterns_total')) $table->unsignedInteger('patterns_total')->default(0);
            if (!Schema::hasColumn('source_names', 'patterns_searched')) $table->unsignedInteger('patterns_searched')->default(0);
            if (!Schema::hasColumn('source_names', 'alteregos_found')) $table->unsignedInteger('alteregos_found')->default(0);
            if (!Schema::hasColumn('source_names', 'current_pattern')) $table->string('current_pattern')->nullable();
            if (!Schema::hasColumn('source_names', 'elapsed_seconds')) $table->unsignedInteger('elapsed_seconds')->default(0);
            if (!Schema::hasColumn('source_names', 'started_at')) $table->timestamp('started_at')->nullable();
            if (!Schema::hasColumn('source_names', 'paused_at')) $table->timestamp('paused_at')->nullable();
            if (!Schema::hasColumn('source_names', 'completed_at')) $table->timestamp('completed_at')->nullable();
        });
    }
};
