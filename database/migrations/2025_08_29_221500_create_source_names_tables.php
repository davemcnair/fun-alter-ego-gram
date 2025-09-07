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

        Schema::create('alter_egos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_name_id')->constrained('source_names')->onDelete('cascade');
            $table->foreignId('source_name_pattern_id')->nullable()->constrained('source_name_patterns')->onDelete('cascade');
            $table->string('phrase');
            $table->timestamps();
            $table->unique(['source_name_id','phrase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alter_egos');
        Schema::dropIfExists('source_name_patterns');
        Schema::dropIfExists('source_names');
    }
};
