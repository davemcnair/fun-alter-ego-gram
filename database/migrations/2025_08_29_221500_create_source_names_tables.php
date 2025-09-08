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
            $table->enum('status', ['idle','running','paused','completed'])->default('idle');
            $table->timestamps();
        });

        Schema::create('source_name_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_name_id')->constrained('source_names')->onDelete('cascade');
            $table->string('pattern_template');
            $table->unsignedInteger('popularity_rank')->default(0)->index();
            $table->enum('status', ['pending','processing','done'])->default('pending');
            $table->timestamps();
            $table->unique(['source_name_id','pattern_template']);
        });

        Schema::create('signatured_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_name_pattern_id')->constrained('source_name_patterns')->onDelete('cascade');
            $table->string('signatured_pattern');
            $table->timestamps();
            $table->unique(['source_name_pattern_id', 'signatured_pattern'], 'sigp_unique');
        });

        Schema::create('matched_words', function (Blueprint $table) {
            $table->foreignId('source_name_id')->constrained('source_name_patterns')->onDelete('cascade');
            $table->foreignId('word_id')->constrained('words')->onDelete('cascade');
            $table->boolean('used');
        });

        Schema::create('alter_egos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signatured_pattern_id')->nullable()->constrained('signatured_patterns')->onDelete('cascade');
            $table->string('phrase');
            $table->boolean('starred');
            $table->timestamps();
            $table->index('starred');
            $table->unique(['source_name_id','phrase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alter_egos');
        Schema::dropIfExists('matched_words');
        Schema::dropIfExists('source_name_patterns');
        Schema::dropIfExists('signatured_patterns');
        Schema::dropIfExists('source_names');
    }
};
