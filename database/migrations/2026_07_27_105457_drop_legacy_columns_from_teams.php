<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'pincode',
                'coordinates',
                'products',
                'is_active',
                'license_valid',
                'installed_date',
                'mobile',
            ]);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('features', 'products');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('products', 'features');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('mobile')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->text('coordinates')->nullable();
            $table->json('products')->nullable();
            $table->boolean('is_active')->default(1);
            $table->date('license_valid')->nullable();
            $table->date('installed_date')->nullable();
        });
    }
};
