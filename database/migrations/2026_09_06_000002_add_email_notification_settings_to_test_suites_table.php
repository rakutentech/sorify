<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->boolean('email_notify_on_start')->default(false)->after('teams_notify_on_failure');
            $table->boolean('email_notify_on_success')->default(false)->after('email_notify_on_start');
            $table->boolean('email_notify_on_failure')->default(false)->after('email_notify_on_success');
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropColumn(['email_notify_on_start', 'email_notify_on_success', 'email_notify_on_failure']);
        });
    }
};
