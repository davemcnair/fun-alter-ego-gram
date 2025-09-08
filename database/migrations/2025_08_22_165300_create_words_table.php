<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anagram_groups', function (Blueprint $table) {
            $table->id();
            $table->string('token_type');
            $table->string('signature');
            $table->timestamps();

            $table->unique(['token_type', 'signature']);
        });

        Schema::table('words', function (Blueprint $table) {
        });
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word');          // Original word
            $table->string('token_type');    // e.g., adjective, noun
            $table->string('list_type');     // e.g., ok, fun, boring
            $table->boolean('use_for_search')->default(true);
            $table->string('signature');     // normalized letters sorted
            $table->foreignId('anagram_group_id')->nullable()->constrained('anagram_groups')->nullOnDelete();
            $table->timestamps();

            $table->index(['token_type', 'signature']);
            $table->index('use_for_search');
            $table->unique(['word', 'token_type', 'list_type']);
        });
        try {
            // SQLite supports partial indexes; others too. Use raw statement for partial unique.
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS words_search_unique ON words(token_type, signature) WHERE use_for_search = 1');
        } catch (Throwable $e) {
            // If not supported, skip; application-level enforcement will still apply.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('words');
        Schema::dropIfExists('anagram_groups');
    }
};
