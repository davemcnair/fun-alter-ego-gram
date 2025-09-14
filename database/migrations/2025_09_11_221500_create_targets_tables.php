<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('signature')->index();
            $table->string('normalized_key')->unique();
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('target_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('targets')->onDelete('cascade');
            $table->foreignId('pattern_id')->constrained('patterns')->onDelete('cascade');
            $table->unsignedInteger('popularity_rank')->default(0)->index();
            $table->string('status');
            $table->timestamps();
            $table->unique(['target_id','pattern_id']);
        });

        Schema::create('target_signature_indexed_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_pattern_id')->constrained('target_patterns')->onDelete('cascade');
            $table->string('pattern');
            $table->timestamps();
            $table->unique(['target_pattern_id', 'pattern'], 'sigp_unique');
        });

        Schema::create('target_token_signature_words', function (Blueprint $table) {
            // Simple 2-column pivot with composite unique; no id/timestamps
            $table->foreignId('target_id')->constrained('targets')->onDelete('cascade');
            $table->foreignId('token_signature_word_id')->constrained('token_signature_words')->onDelete('cascade');
            $table->boolean('is_new')->default(false);

            $table->unique(['target_id', 'token_signature_word_id'], 'matched_words_unique');
            $table->index('token_signature_word_id');
            $table->index('target_id');
            // Add helpful indexes for queries by is_new
            $table->index(['target_id', 'is_new'], 'ttsw_target_isnew_idx');
            $table->index(['token_signature_word_id', 'is_new'], 'ttsw_word_isnew_idx');
        });

        Schema::create('alter_egos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_signature_indexed_pattern_id')->constrained('target_signature_indexed_patterns')->onDelete('cascade');
            $table->string('phrase');
            $table->boolean('starred')->default(false);
            $table->timestamps();
            $table->index('starred');
            $table->unique(['target_signature_indexed_pattern_id','phrase']);
        });

        Schema::create('target_token_signature_words_alter_egos', function (Blueprint $table) {
            // Composite pivot referencing the 2-column parent key
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('token_signature_word_id');
            $table->unsignedBigInteger('alter_ego_id');

            $table->foreign(['target_id', 'token_signature_word_id'])
                ->references(['target_id', 'token_signature_word_id'])
                ->on('target_token_signature_words')
                ->onDelete('cascade');

            $table->foreign('alter_ego_id')
                ->references('id')
                ->on('alter_egos')
                ->onDelete('cascade');

            $table->primary(['target_id', 'token_signature_word_id', 'alter_ego_id'], 'ttsw_ae_pk');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('target_token_signature_words_alter_egos');
        Schema::dropIfExists('alter_egos');
        Schema::dropIfExists('target_token_signature_words');
        Schema::dropIfExists('target_signature_indexed_patterns');
        Schema::dropIfExists('target_patterns');
        Schema::dropIfExists('targets');
    }
};
