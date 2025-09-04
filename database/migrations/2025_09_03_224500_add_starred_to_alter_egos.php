<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alter_egos', function (Blueprint $table) {
            if (!Schema::hasColumn('alter_egos', 'starred')) {
                $table->boolean('starred')->default(false)->after('phrase');
                $table->index('starred');
            }
        });
    }

    public function down(): void
    {
        Schema::table('alter_egos', function (Blueprint $table) {
            if (Schema::hasColumn('alter_egos', 'starred')) {
                $table->dropIndex(['starred']);
                $table->dropColumn('starred');
            }
        });
    }
};
