<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope pith location names to their group, so "East" can exist under both Alam
 * and Kannappa Gounder now that the group carries the site.
 *
 * A unique key over group_id alone would not cover ungrouped locations, since
 * MySQL treats NULLs as distinct. The group_key generated column collapses NULL
 * to 0 so the ungrouped bucket is constrained like any other group.
 *
 * The column must be VIRTUAL, not STORED: MySQL forbids a *stored* generated
 * column over a foreign key declared ON DELETE SET NULL, which is how group_id is
 * defined. Virtual columns carry no such restriction and index just as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_pith_locations') || ! Schema::hasColumn('pulse_pith_locations', 'group_id')) {
            return;
        }

        $this->dropIndexIfExists('uniq_team_group_location');

        if (! Schema::hasColumn('pulse_pith_locations', 'group_key')) {
            Schema::table('pulse_pith_locations', function (Blueprint $table) {
                $table->unsignedInteger('group_key')->virtualAs('COALESCE(group_id, 0)')->after('group_id');
            });
        }

        Schema::table('pulse_pith_locations', function (Blueprint $table) {
            $table->unique(['team_id', 'group_key', 'name'], 'uniq_team_group_location');
        });

        // The old team-wide key is what blocked the same name under two groups.
        $this->dropIndexIfExists('uniq_team_location');
    }

    public function down(): void
    {
        if (! Schema::hasTable('pulse_pith_locations')) {
            return;
        }

        // Restoring the team-wide key is only possible if no team reuses a name
        // across groups; leave it off rather than fail the rollback.
        $clashes = DB::table('pulse_pith_locations')
            ->select('team_id', 'name')
            ->groupBy('team_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $clashes && ! $this->indexExists('uniq_team_location')) {
            Schema::table('pulse_pith_locations', function (Blueprint $table) {
                $table->unique(['team_id', 'name'], 'uniq_team_location');
            });
        }

        $this->dropIndexIfExists('uniq_team_group_location');

        if (Schema::hasColumn('pulse_pith_locations', 'group_key')) {
            Schema::table('pulse_pith_locations', function (Blueprint $table) {
                $table->dropColumn('group_key');
            });
        }
    }

    private function indexExists(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'pulse_pith_locations')
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function dropIndexIfExists(string $name): void
    {
        if (! $this->indexExists($name)) {
            return;
        }

        Schema::table('pulse_pith_locations', function (Blueprint $table) use ($name) {
            $table->dropUnique($name);
        });
    }
};
