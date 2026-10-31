<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the factory-wide notification switch.
 *
 * It duplicated controls that now sit at both ends: a factory that should send
 * nothing is deactivated, and everyone chooses their own notifications on the
 * My Notifications screen. Keeping a third switch in between only created a way
 * to silence a whole factory without anyone in it knowing why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('team_pulse', 'enable_notifications')) {
            Schema::table('team_pulse', function (Blueprint $blueprint) {
                $blueprint->dropColumn('enable_notifications');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('team_pulse', 'enable_notifications')) {
            Schema::table('team_pulse', function (Blueprint $blueprint) {
                $blueprint->boolean('enable_notifications')->default(true)->after('modules');
            });
        }
    }
};
