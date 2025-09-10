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
            $table->foreignId('signature_id')->constrained('signatures')->cascadeOnDelete();
            $table->string('list_type');
            $table->string('word');
            $table->boolean('is_deferred')->default(false);
            $table->timestamps();

            $table->unique(['signature_id', 'list_type', 'word']);
            $table->index('is_deferred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_signature_words');
    }
};
