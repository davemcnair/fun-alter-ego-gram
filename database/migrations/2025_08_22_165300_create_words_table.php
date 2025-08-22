<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word');          // Original word
            $table->string('token_type');    // e.g., adjective, noun
            $table->string('list_type');     // e.g., ok, fun, boring
            $table->string('signature');     // normalized letters sorted
            $table->timestamps();

            $table->unique(['word', 'token_type', 'list_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
