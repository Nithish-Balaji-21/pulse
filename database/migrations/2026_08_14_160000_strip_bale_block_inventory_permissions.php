<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_team_roles')) {
            return;
        }

        DB::table('pulse_team_roles')
            ->select(['id', 'permissions'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $role) {
                    $permissions = is_string($role->permissions)
                        ? json_decode($role->permissions, true)
                        : $role->permissions;

                    if (! is_array($permissions)) {
                        continue;
                    }

                    $filtered = array_values(array_filter(
                        $permissions,
                        fn (string $capability) => ! str_starts_with($capability, 'bale_inventory:')
                            && ! str_starts_with($capability, 'block_inventory:')
                    ));

                    if ($filtered === $permissions) {
                        continue;
                    }

                    DB::table('pulse_team_roles')
                        ->where('id', $role->id)
                        ->update(['permissions' => json_encode($filtered)]);
                }
            });
    }

    public function down(): void
    {
        // Capabilities were removed intentionally; defaults are re-seeded via role editor.
    }
};
