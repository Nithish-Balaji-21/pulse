<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('settings', 'pulse_settings');
    }

    public function down(): void
    {
        Schema::rename('pulse_settings', 'settings');
    }
};
