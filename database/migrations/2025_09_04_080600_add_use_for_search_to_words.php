<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            if (!Schema::hasColumn('words', 'use_for_search')) {
                $table->boolean('use_for_search')->default(true)->after('list_type');
                $table->index('use_for_search');
            }
        });
        // Add a partial unique index so only one search word exists per (token_type, signature)
        try {
            // SQLite supports partial indexes; others too. Use raw statement for partial unique.
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS words_search_unique ON words(token_type, signature) WHERE use_for_search = 1');
        } catch (Throwable $e) {
            // If not supported, skip; application-level enforcement will still apply.
        }
    }

    public function down(): void
    {
        // Drop partial index if exists
        try {
            DB::statement('DROP INDEX IF EXISTS words_search_unique');
        } catch (Throwable $e) {}
        Schema::table('words', function (Blueprint $table) {
            if (Schema::hasColumn('words', 'use_for_search')) {
                $table->dropIndex(['use_for_search']);
                $table->dropColumn('use_for_search');
            }
        });
    }
};
