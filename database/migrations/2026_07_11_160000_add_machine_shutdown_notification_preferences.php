<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('pulse_user_notification_preferences')
            ->where('notification_type', 'MACHINE_OFF')
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($existing as $pref) {
            $rows[] = [
                'user_id' => $pref->user_id,
                'team_id' => $pref->team_id,
                'notification_type' => 'MACHINE_SHUTDOWN',
                'is_email_enabled' => $pref->is_email_enabled,
                'is_push_enabled' => $pref->is_push_enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pulse_user_notification_preferences')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('pulse_user_notification_preferences')
            ->where('notification_type', 'MACHINE_SHUTDOWN')
            ->delete();
    }
};
