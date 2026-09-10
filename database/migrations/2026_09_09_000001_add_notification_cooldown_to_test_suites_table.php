<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->unsignedSmallInteger('teams_notification_cooldown_minutes')->default(0)->after('email_notify_on_failure');
            $table->unsignedSmallInteger('email_notification_cooldown_minutes')->default(0)->after('teams_notification_cooldown_minutes');
            $table->timestamp('teams_last_notified_at')->nullable()->after('email_notification_cooldown_minutes');
            $table->timestamp('email_last_notified_at')->nullable()->after('teams_last_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropColumn([
                'teams_notification_cooldown_minutes',
                'email_notification_cooldown_minutes',
                'teams_last_notified_at',
                'email_last_notified_at',
            ]);
        });
    }
};
