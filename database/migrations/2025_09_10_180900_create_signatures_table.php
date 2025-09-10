<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('tokens')->cascadeOnDelete();
            $table->string('signature');
            $table->timestamps();

            $table->unique(['token_id', 'signature']);
            $table->index('signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
