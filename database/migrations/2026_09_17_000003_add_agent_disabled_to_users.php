<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin kill-switch: when set, the user cannot use the AI agent
        // (chats, profiles, buttons) and the UI tells them to contact an
        // admin. Off by default — agents are allowed.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('agent_disabled')->default(false)->after('is_view_only');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agent_disabled');
        });
    }
};
