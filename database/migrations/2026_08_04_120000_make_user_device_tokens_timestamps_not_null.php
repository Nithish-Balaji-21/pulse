<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent()->nullable(false)->change();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent()->nullable()->change();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable()->change();
        });
    }
};
