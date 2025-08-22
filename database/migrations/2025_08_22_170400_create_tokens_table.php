<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('prio');
            $table->unsignedInteger('min_length')->default(0);
            $table->boolean('allow_nearly')->default(false);
            $table->boolean('has_fun')->default(false);
            $table->boolean('has_boring')->default(false);
            $table->unsignedInteger('max_multiples')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
