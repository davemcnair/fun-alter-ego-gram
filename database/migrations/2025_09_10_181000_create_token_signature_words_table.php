<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_signature_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_signature_id')->constrained('token_signatures')->cascadeOnDelete();
            $table->string('list_type');
            $table->string('word');
            // is_deferred from phrase generation
            $table->boolean('is_deferred')->default(false);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(['token_signature_id', 'list_type', 'word']);
            $table->index('is_deferred');
            $table->index(['token_signature_id', 'list_type'], 'tsw_token_list_idx');
            $table->index(['is_deferred', 'list_type', 'token_signature_id'], 'tsw_deferred_list_token_idx');
            $table->index('committed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_signature_words');
    }
};
