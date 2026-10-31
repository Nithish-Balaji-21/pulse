<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('team_invitations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank as team_invitations is permanently removed
    }
};
