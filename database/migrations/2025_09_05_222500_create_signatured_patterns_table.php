<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatured_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_name_pattern_id');
            $table->string('signatured_pattern');
            $table->timestamps();

            $table->index('source_name_pattern_id');
            $table->unique(['source_name_pattern_id', 'signatured_pattern'], 'sigp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatured_patterns');
    }
};
