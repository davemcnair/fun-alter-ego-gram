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
            $table->foreignId('signature_id')->constrained('signatures')->onDelete('cascade');
            $table->string('normalized_key')->unique();
            $table->string('status');
            $table->dateTime('matches_seen_at')->nullable();
            // Processing watermark: last time we processed new matches
            $table->dateTime('last_processed_matches_at')->nullable();
            $table->timestamps();
        });

        Schema::create('target_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('targets')->onDelete('cascade');
            $table->foreignId('pattern_id')->constrained('patterns')->onDelete('cascade');
            $table->unsignedInteger('popularity_rank')->default(0)->index();
            $table->string('status');
            // Inline timing fields (no queues): record start/finish and elapsed in ms
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->timestamps();
            $table->unique(['target_id','pattern_id']);
        });

        /**
         * Parent for unique set of filled signature_ids for a pattern
         * todo: Consider uniqueness? if you add pattern_ids_csv:
         *      $table->unique(['target_pattern_id', 'pattern_ids_csv'], 'tsp_pattern_unique');
         * todo: consider set equivalence, shudder...
         */

        Schema::create('target_signatured_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_pattern_id')->constrained('target_patterns')->onDelete('cascade');
            $table->timestamps();
            $table->index('target_pattern_id');
        });

        Schema::create('target_token_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('targets')->onDelete('cascade');
            $table->foreignId('token_signature_id')->constrained('token_signatures')->onDelete('cascade');
            $table->boolean('usedInPattern')->default(false);
            $table->timestamps();

            $table->index('created_at', 'tts_created_at_idx');
            $table->unique(['target_id', 'token_signature_id'], 'matched_signatures_unique');
            $table->index('token_signature_id');
            $table->index('target_id');
        });

        // pivot table
        Schema::create('target_signatured_pattern_target_token_signature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_signatured_pattern_id')
                ->constrained('target_signatured_patterns')
                ->onDelete('cascade');
            $table->foreignId('target_token_signature_id')
                ->constrained('target_token_signatures')
                ->onDelete('cascade');
            $table->unsignedInteger('position'); // The index/order from the array

            // Unique constraint: same signature can appear multiple times in a pattern,
            // but not at the same position
            $table->unique([
                'target_signatured_pattern_id',
                'target_token_signature_id',
                'position'
            ], 'tsp_position_unique');

            // Optional: Add index for querying by position
            $table->index(['target_signatured_pattern_id', 'position']);
            // For reverse lookups
            $table->index('target_token_signature_id');
        });

        Schema::create('target_token_signature_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('targets')->onDelete('cascade');
            $table->foreignId('token_signature_word_id')->constrained('token_signature_words')->onDelete('cascade');
            $table->boolean('usedInPhrase')->default(false); // in search
            $table->timestamps();

            $table->index('created_at', 'ttsw_created_at_idx');
            $table->unique(['target_id', 'token_signature_word_id'], 'matched_signature_words_unique');
            $table->index('token_signature_word_id');
            $table->index('target_id');
        });

        Schema::create('alter_egos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_signatured_pattern_id')->constrained('target_signatured_patterns')->onDelete('cascade');
            $table->string('phrase');
            $table->boolean('starred')->default(false);
            $table->boolean('isFun')->default(false);
            $table->boolean('hasBoring')->default(false);
            $table->boolean('hasDeferred')->default(false);
            $table->unique(['target_signatured_pattern_id','phrase']);
            $table->timestamps();
        });

        /**
         * Track which words from signatures were actually used in generating each alter ego phrase
         * Enable phrase search by constituent words
         * Support the usedInPhrase flag on target_token_signature_words
         * Allow reverse lookup: "which alter egos use this signature word?"
         */
        Schema::create('target_token_signature_words_alter_egos', function (Blueprint $table) {
            $table->unsignedBigInteger('target_token_signature_word_id');
            $table->unsignedBigInteger('alter_ego_id');

            $table->foreign('target_token_signature_word_id')
                ->references('id')
                ->on('target_token_signature_words')
                ->onDelete('cascade');

            $table->foreign('alter_ego_id')
                ->references('id')
                ->on('alter_egos')
                ->onDelete('cascade');

            $table->primary(['target_token_signature_word_id', 'alter_ego_id'], 'ttsw_ae_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_token_signature_words_alter_egos');
        Schema::dropIfExists('alter_egos');
        Schema::dropIfExists('target_signatured_pattern_target_token_signature');
        Schema::dropIfExists('target_token_signature_words');
        Schema::dropIfExists('target_token_signatures');
        Schema::dropIfExists('target_signatured_patterns');
        Schema::dropIfExists('target_patterns');
        Schema::dropIfExists('targets');
    }
};
