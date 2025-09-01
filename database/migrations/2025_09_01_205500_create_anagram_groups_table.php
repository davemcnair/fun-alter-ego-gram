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
            $table->string('signature'); // normalized, sorted letters
            $table->unsignedInteger('words_count')->default(0);
            $table->timestamps();
            $table->unique(['token_type', 'signature']);
        });

        Schema::table('words', function (Blueprint $table) {
            $table->foreignId('anagram_group_id')->nullable()->after('signature')->constrained('anagram_groups')->nullOnDelete();
            $table->index(['token_type', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            if (Schema::hasColumn('words', 'anagram_group_id')) {
                $table->dropConstrainedForeignId('anagram_group_id');
            }
        });
        Schema::dropIfExists('anagram_groups');
    }
};
