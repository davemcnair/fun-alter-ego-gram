<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_names', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('signature')->index();
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('source_name_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_name_id')->constrained('source_names')->onDelete('cascade');
            $table->foreignId('pattern_id')->constrained('patterns')->onDelete('cascade');
            $table->unsignedInteger('popularity_rank')->default(0)->index();
            $table->string('status');
            $table->timestamps();
            $table->unique(['source_name_id','pattern_id']);
        });

        Schema::create('signature_indexed_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_name_pattern_id')->constrained('source_name_patterns')->onDelete('cascade');
            $table->string('pattern');
            $table->timestamps();
            $table->unique(['source_name_pattern_id', 'pattern'], 'sigp_unique');
        });

        Schema::create('source_name_matched_words', function (Blueprint $table) {
            $table->foreignId('source_name_id')->constrained('source_names')->onDelete('cascade');
            $table->foreignId('token_signature_word_id')->constrained('token_signature_words')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['source_name_id', 'token_signature_word_id'], 'matched_words_unique');
            $table->index('token_signature_word_id');
            $table->index('source_name_id');
        });

        Schema::create('source_name_matched_words_alter_egos', function (Blueprint $table) {
            $table->foreignId('source_name_matched_word_id')->constrained('source_name_matched_words')->onDelete('cascade');
            $table->foreignId('alter_ego_id')->constrained('alter_egos')->onDelete('cascade');
        });

        Schema::create('alter_egos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_indexed_pattern_id')->constrained('signature_indexed_patterns')->onDelete('cascade');
            $table->string('phrase');
            $table->boolean('starred')->default(false);
            $table->timestamps();
            $table->index('starred');
            $table->unique(['signature_indexed_pattern_id','phrase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alter_egos');
        Schema::dropIfExists('source_name_matched_words_alter_egos');
        Schema::dropIfExists('source_name_matched_words');
        Schema::dropIfExists('signature_indexed_patterns');
        Schema::dropIfExists('source_name_patterns');
        Schema::dropIfExists('source_names');
    }
};
