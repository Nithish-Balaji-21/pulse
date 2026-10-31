<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = Schema::hasTable('pulse_fibre_minibale_productions')
            ? 'pulse_fibre_minibale_productions'
            : 'fibre_minibale_productions';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'production_hours')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->decimal('production_hours', 5, 2)->default(0)->after('bales_produced');
        });
    }

    public function down(): void
    {
        $tableName = Schema::hasTable('pulse_fibre_minibale_productions')
            ? 'pulse_fibre_minibale_productions'
            : 'fibre_minibale_productions';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'production_hours')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('production_hours');
        });
    }
};
