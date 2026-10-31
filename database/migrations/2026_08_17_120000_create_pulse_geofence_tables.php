<?php

use App\Support\Pulse\GeofencingModule;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_geofence_locations')) {
            Schema::create('pulse_geofence_locations', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team_id');
                $table->string('name', 100);
                $table->string('type', 50)->nullable();
                $table->string('boundary_type', 20)->default('circle');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->unsignedInteger('radius')->nullable();
                $table->json('polygon_coordinates')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();

                $table->index(['team_id', 'status']);
                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('pulse_geofence_events')) {
            Schema::create('pulse_geofence_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('team_id');
                $table->unsignedInteger('geofence_location_id');
                $table->string('event_type', 10);
                $table->decimal('latitude', 10, 8);
                $table->decimal('longitude', 11, 8);
                $table->dateTime('recorded_at');
                $table->dateTime('received_at');
                $table->timestamps();

                $table->index(['team_id', 'user_id', 'recorded_at'], 'idx_pulse_geofence_events_team_user_time');
                $table->index(['geofence_location_id', 'recorded_at'], 'idx_pulse_geofence_events_location_time');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
                $table->foreign('geofence_location_id')
                    ->references('id')
                    ->on('pulse_geofence_locations')
                    ->cascadeOnDelete();
            });
        }

        $this->ensureGeofencingModule();
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_geofence_events');
        Schema::dropIfExists('pulse_geofence_locations');

        if (! Schema::hasTable('team_pulse')) {
            return;
        }

        $rows = DB::table('team_pulse')->select('id', 'modules')->get();
        foreach ($rows as $row) {
            $modules = is_string($row->modules) ? json_decode($row->modules, true) : (array) $row->modules;
            if (! is_array($modules) || ! isset($modules['pulse']['modules']['geofencing'])) {
                continue;
            }

            unset($modules['pulse']['modules']['geofencing']);
            DB::table('team_pulse')->where('id', $row->id)->update([
                'modules' => json_encode($modules),
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureGeofencingModule(): void
    {
        if (! Schema::hasTable('team_pulse')) {
            return;
        }

        $defaultMax = GeofencingModule::defaultMaxBoundaries();
        $counts = [];
        if (Schema::hasTable('pulse_geofence_locations')) {
            $counts = DB::table('pulse_geofence_locations')
                ->select('team_id', DB::raw('COUNT(*) as total'))
                ->groupBy('team_id')
                ->pluck('total', 'team_id')
                ->all();
        }

        $rows = DB::table('team_pulse')->select('id', 'team_id', 'modules')->get();
        foreach ($rows as $row) {
            $modules = is_string($row->modules) ? json_decode($row->modules, true) : (array) $row->modules;
            if (! is_array($modules)) {
                continue;
            }

            $existing = $modules['pulse']['modules']['geofencing'] ?? [];
            $locationCount = (int) ($counts[$row->team_id] ?? 0);
            $isActive = (bool) ($existing['is_active'] ?? false) || $locationCount > 0;
            $storedMax = isset($existing['max_boundaries']) ? (int) $existing['max_boundaries'] : $defaultMax;

            $modules['pulse']['modules']['geofencing'] = GeofencingModule::payload(
                $isActive,
                max($storedMax, $locationCount, $defaultMax)
            );

            DB::table('team_pulse')->where('id', $row->id)->update([
                'modules' => json_encode($modules),
                'updated_at' => now(),
            ]);

            if ($isActive) {
                TeamRolePermissions::syncOnModuleChange((int) $row->team_id);
            }
        }
    }
};
