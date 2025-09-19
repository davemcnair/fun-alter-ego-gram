<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // todo: convert to pivot table?
        Schema::create('token_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('tokens')->cascadeOnDelete();
            $table->foreignId('signature_id')->constrained('signatures')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['token_id', 'signature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_signatures');
    }
};
