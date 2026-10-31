<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = DB::table('teams')->get()->keyBy('id');

        foreach (DB::table('team_user')->get() as $membership) {
            $team = $teams->get($membership->team_id);
            $products = json_decode($team->products ?? '{}', true) ?? [];

            $access = [];
            if ($products['pulse'] ?? false) {
                $access[] = 'pulse';
            }

            if (empty($access)) {
                $access = ['pulse'];
            }

            DB::table('team_user')
                ->where('team_id', $membership->team_id)
                ->where('user_id', $membership->user_id)
                ->update(['product_access' => json_encode($access)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('team_user')->update(['product_access' => null]);
    }
};
