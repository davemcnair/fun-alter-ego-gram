<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patterns', function (Blueprint $table) {
            $table->id();
            $table->string('template')->unique();
            $table->unsignedInteger('popularity_rank')->default(0)->index();
            $table->string('pattern_type', 20)->default('exotic');
            $table->unsignedInteger('min_total_length')->default(0);
            // Optional descriptive metadata - may help future UI/filters
            $table->unsignedTinyInteger('forename_count')->default(0);
            $table->unsignedTinyInteger('surname_count')->default(0);
            $table->boolean('has_title')->default(false);
            $table->boolean('has_initials')->default(false);
            $table->boolean('has_prefix')->default(false);
            $table->boolean('has_suffix')->default(false);
            $table->boolean('has_honorific')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patterns');
    }
};
