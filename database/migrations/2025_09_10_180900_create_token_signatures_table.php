<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('tokens')->cascadeOnDelete();
            $table->string('signature');
            // Derived length and per-letter counts for exact subset pruning
            $table->unsignedTinyInteger('sig_len')->default(0);
            $table->unsignedTinyInteger('a_count')->default(0);
            $table->unsignedTinyInteger('b_count')->default(0);
            $table->unsignedTinyInteger('c_count')->default(0);
            $table->unsignedTinyInteger('d_count')->default(0);
            $table->unsignedTinyInteger('e_count')->default(0);
            $table->unsignedTinyInteger('f_count')->default(0);
            $table->unsignedTinyInteger('g_count')->default(0);
            $table->unsignedTinyInteger('h_count')->default(0);
            $table->unsignedTinyInteger('i_count')->default(0);
            $table->unsignedTinyInteger('j_count')->default(0);
            $table->unsignedTinyInteger('k_count')->default(0);
            $table->unsignedTinyInteger('l_count')->default(0);
            $table->unsignedTinyInteger('m_count')->default(0);
            $table->unsignedTinyInteger('n_count')->default(0);
            $table->unsignedTinyInteger('o_count')->default(0);
            $table->unsignedTinyInteger('p_count')->default(0);
            $table->unsignedTinyInteger('q_count')->default(0);
            $table->unsignedTinyInteger('r_count')->default(0);
            $table->unsignedTinyInteger('s_count')->default(0);
            $table->unsignedTinyInteger('t_count')->default(0);
            $table->unsignedTinyInteger('u_count')->default(0);
            $table->unsignedTinyInteger('v_count')->default(0);
            $table->unsignedTinyInteger('w_count')->default(0);
            $table->unsignedTinyInteger('x_count')->default(0);
            $table->unsignedTinyInteger('y_count')->default(0);
            $table->unsignedTinyInteger('z_count')->default(0);
            $table->timestamps();

            $table->unique(['token_id', 'signature']);
            $table->index('signature');
            $table->index('sig_len', 'token_signatures_sig_len_idx');
            $table->index(['token_id', 'sig_len'], 'token_signatures_token_sig_len_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_signatures');
    }
};
