<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->foreignId('teams_last_notified_run_id')->nullable()->after('teams_last_notified_at')->constrained('test_runs')->nullOnDelete();
            $table->foreignId('email_last_notified_run_id')->nullable()->after('email_last_notified_at')->constrained('test_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropForeign(['teams_last_notified_run_id']);
            $table->dropForeign(['email_last_notified_run_id']);
            $table->dropColumn(['teams_last_notified_run_id', 'email_last_notified_run_id']);
        });
    }
};
