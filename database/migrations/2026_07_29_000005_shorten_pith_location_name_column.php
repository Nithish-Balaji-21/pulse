<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cap pith location names at 20 characters so they stay readable in the grouped
 * inventory list and the load-entry modal, where the group already names the site.
 *
 * The only names over the limit are the Kannappa Gounder ones, which repeat their
 * group; they are shortened first. Any other over-length name found at run time
 * aborts the column change rather than letting MySQL truncate it.
 */
return new class extends Migration
{
    /**
     * Old name => new name, applied per team so a factory that never had these
     * is simply skipped.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
        'kannappa gounder east' => 'KG East',
        'kannappa gounder west' => 'KG West',
        'kannappa gounder to alam pith drying' => 'KG to Alam Drying',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pulse_pith_locations')) {
            return;
        }

        foreach (self::RENAMES as $from => $to) {
            $matches = DB::table('pulse_pith_locations')->where('name', $from)->get(['id', 'team_id']);

            foreach ($matches as $location) {
                // Checked per row rather than as a subquery: MySQL rejects a
                // self-referencing subquery inside an UPDATE on the same table.
                $taken = DB::table('pulse_pith_locations')
                    ->where('team_id', $location->team_id)
                    ->where('name', $to)
                    ->exists();

                if ($taken) {
                    continue;
                }

                DB::table('pulse_pith_locations')
                    ->where('id', $location->id)
                    ->update(['name' => $to, 'updated_at' => now()]);
            }
        }

        $stillTooLong = DB::table('pulse_pith_locations')
            ->whereRaw('CHAR_LENGTH(name) > 20')
            ->pluck('name');

        if ($stillTooLong->isNotEmpty()) {
            // Shrinking now would silently truncate real names, so leave the column
            // alone; validation already caps new and edited names at 20.
            Log::warning('Skipped pith location name column shrink: names still exceed 20 characters', [
                'names' => $stillTooLong->all(),
            ]);

            return;
        }

        Schema::table('pulse_pith_locations', function (Blueprint $table) {
            $table->string('name', 20)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pulse_pith_locations')) {
            return;
        }

        Schema::table('pulse_pith_locations', function (Blueprint $table) {
            $table->string('name', 50)->nullable(false)->change();
        });

        foreach (self::RENAMES as $from => $to) {
            DB::table('pulse_pith_locations')
                ->where('name', $to)
                ->update(['name' => $from, 'updated_at' => now()]);
        }
    }
};
