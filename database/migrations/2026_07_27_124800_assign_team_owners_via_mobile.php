<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Backfill owners from legacy teams.user_id for every team missing an owner row.
        if (Schema::hasColumn('teams', 'user_id')) {
            $teams = DB::table('teams')
                ->whereNotNull('user_id')
                ->where('user_id', '>', 0)
                ->get(['id', 'user_id']);

            foreach ($teams as $team) {
                $hasOwner = DB::table('team_user')
                    ->where('team_id', $team->id)
                    ->where('role', 'owner')
                    ->exists();

                if ($hasOwner) {
                    continue;
                }

                DB::table('team_user')->updateOrInsert(
                    ['team_id' => $team->id, 'user_id' => $team->user_id],
                    ['role' => 'owner', 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // 2. Override / assign known owners by factory name + owner mobile.
        $mapping = [
            'AG Coirs' => '9942664500',
            'Alaya Coirs' => '7373633322',
            'Alaya Coirs Unit 2' => '7373633322',
            'App Store Test Factory' => '8248013779',
            'AVM Fibres' => '8248013779',
            'Hexagro Fibre' => '8248013779',
            'HP Cocos' => '9865352181',
            'PKS Coir Enterprises' => '9787939777',
            'Ramesh Coirs' => '9524418880',
            'Alam Cocos' => '8248013779',
            'Sri Krishna Coir Impexs' => '9965150273',
        ];

        foreach ($mapping as $teamName => $mobile) {
            $team = DB::table('teams')->where('name', $teamName)->first();

            if ($team) {
                $user = DB::table('users')->where('mobile', $mobile)->first();

                if ($user) {
                    DB::table('team_user')->updateOrInsert(
                        ['team_id' => $team->id, 'user_id' => $user->id],
                        ['role' => 'owner', 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $mapping = [
            'AG Coirs' => '9942664500',
            'Alaya Coirs' => '7373633322',
            'Alaya Coirs Unit 2' => '7373633322',
            'App Store Test Factory' => '8248013779',
            'AVM Fibres' => '8248013779',
            'Hexagro Fibre' => '8248013779',
            'HP Cocos' => '9865352181',
            'PKS Coir Enterprises' => '9787939777',
            'Ramesh Coirs' => '9524418880',
            'Alam Cocos' => '8248013779',
            'Sri Krishna Coir Impexs' => '9965150273',
        ];

        foreach ($mapping as $teamName => $mobile) {
            $team = DB::table('teams')->where('name', $teamName)->first();
            $user = DB::table('users')->where('mobile', $mobile)->first();

            if ($team && $user) {
                DB::table('team_user')
                    ->where('team_id', $team->id)
                    ->where('user_id', $user->id)
                    ->where('role', 'owner')
                    ->delete();
            }
        }
    }
};
