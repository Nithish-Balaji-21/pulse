<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the batched age-based purge in `data:cleanup`. Without it each batch
     * full-scans the table, and this is the fastest-growing table the cleanup touches.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index('created_at', 'idx_notifications_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_created_at');
        });
    }
};
