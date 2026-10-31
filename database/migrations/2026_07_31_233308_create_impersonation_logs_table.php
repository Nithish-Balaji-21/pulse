<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One chronological trail per impersonation: the session lifecycle events
     * (start/stop/write-mode) plus every model mutation made while it was open,
     * grouped by `session_uuid`.
     */
    public function up(): void
    {
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->index();

            // `users.id` and `teams.id` are `int unsigned`, not bigint — foreignId()
            // would emit a bigint column that MySQL refuses to constrain against them.
            $table->unsignedInteger('impersonator_id');
            $table->unsignedInteger('impersonated_id');
            $table->unsignedInteger('team_id')->nullable()->index();

            $table->foreign('impersonator_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('impersonated_id')->references('id')->on('users')->cascadeOnDelete();

            // start | stop | write_mode_enabled | write_mode_disabled | created | updated | deleted
            $table->string('action', 32);

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Dirty attributes for created/updated; the vanishing row for deleted.
            $table->json('changes')->nullable();

            $table->string('url', 1024)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();

            $table->index(['impersonator_id', 'created_at']);
            $table->index(['impersonated_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_logs');
    }
};
