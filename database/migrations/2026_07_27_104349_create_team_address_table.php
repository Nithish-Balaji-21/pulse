<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('team_address', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->enum('type', ['factory', 'billing', 'shipping'])->default('factory');
            $table->string('address_line_1', 255)->nullable();
            $table->string('address_line_2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->timestamps();
        });

        // Add known addresses statically as requested
        DB::table('team_address')->insert([
            [
                'team_id' => 2, // Alam Cocos
                'type' => 'factory',
                'address_line_1' => 'SF 22/2B1',
                'address_line_2' => 'Aandipalayam, Negamam',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'pincode' => '642120',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'team_id' => 15, // Sri Krishna Coir Impexs
                'type' => 'factory',
                'address_line_1' => 'SF No. 4',
                'address_line_2' => 'Seelakkampatti, Pollachi',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'pincode' => '642204',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_address');
    }
};
