<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coiiot_settings') && ! Schema::hasTable('pulse_superadmin_settings')) {
            Schema::rename('coiiot_settings', 'pulse_superadmin_settings');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pulse_superadmin_settings') && ! Schema::hasTable('coiiot_settings')) {
            Schema::rename('pulse_superadmin_settings', 'coiiot_settings');
        }
    }
};
