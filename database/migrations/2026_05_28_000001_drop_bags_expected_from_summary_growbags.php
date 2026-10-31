<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('summary_growbags') && Schema::hasColumn('summary_growbags', 'bags_expected')) {
            Schema::table('summary_growbags', function (Blueprint $table) {
                $table->dropColumn('bags_expected');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('summary_growbags') && ! Schema::hasColumn('summary_growbags', 'bags_expected')) {
            Schema::table('summary_growbags', function (Blueprint $table) {
                $table->unsignedInteger('bags_expected')->default(0);
            });
        }
    }
};
