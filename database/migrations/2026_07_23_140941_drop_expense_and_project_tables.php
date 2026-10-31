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
        // pulse_attendance.project_id FKs into pulse_projects — drop the constraint
        // and the now-unused column before pulse_projects itself can be dropped.
        Schema::table('pulse_attendance', function (Blueprint $table) {
            $table->dropForeign('attendance_fk_project');
            $table->dropColumn('project_id');
        });

        // pulse_expenses FKs into pulse_projects too; dropping the table removes
        // that constraint along with it.
        Schema::dropIfExists('pulse_expenses');
        Schema::dropIfExists('pulse_expense_categories');
        Schema::dropIfExists('pulse_projects');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('pulse_projects', function (Blueprint $table) {
            $table->id();
            $table->integer('team_id')->nullable();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'inactive']);
            $table->timestamps();
            $table->index('team_id');
        });

        Schema::create('pulse_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'name'], 'uniq_team_category_name');
        });

        Schema::create('pulse_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->date('date')->nullable();
            $table->integer('staff_id')->nullable();
            $table->unsignedInteger('project_id')->nullable();
            $table->integer('expense_category_id')->nullable();
            $table->string('expense_type', 50)->nullable();
            $table->double('amount')->nullable();
            $table->double('tax')->nullable();
            $table->string('invoice_no', 50)->nullable();
            $table->integer('paid_by')->nullable();
            $table->text('attachments')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'date', 'expense_category_id', 'amount'], 'idx_team_date_category_amount');
            $table->foreign('project_id', 'expenses_fk_project')->references('id')->on('pulse_projects');
        });

        Schema::table('pulse_attendance', function (Blueprint $table) {
            $table->unsignedInteger('project_id')->nullable()->after('staff_id');
            $table->foreign('project_id', 'attendance_fk_project')->references('id')->on('pulse_projects');
        });
    }
};
