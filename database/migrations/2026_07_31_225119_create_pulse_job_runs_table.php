<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-team execution ledger for the nightly device-data jobs.
     *
     * `cron_runs` only records that a command dispatched, at whole-command
     * granularity, so it reports success even when every queued job is lost.
     * This table records one row per dispatched job per team per shift day and
     * the job stamps `completed_at` itself, so an unprocessed team is detectable.
     */
    public function up(): void
    {
        Schema::create('pulse_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 100);
            $table->unsignedBigInteger('team_id');
            $table->date('run_date');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['job_name', 'team_id', 'run_date'], 'uniq_job_team_date');
            $table->index(['run_date', 'completed_at'], 'idx_run_date_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_job_runs');
    }
};
