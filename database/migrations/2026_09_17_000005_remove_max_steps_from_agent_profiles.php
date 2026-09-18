<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The per-turn step budget moved out of agent profiles into the chat
        // box (sent with each chat request), so the column is gone.
        Schema::table('agent_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('agent_profiles', 'max_steps')) {
                $table->dropColumn('max_steps');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_profiles', 'max_steps')) {
                $table->unsignedInteger('max_steps')->nullable()->after('history_retention_days');
            }
        });
    }
};
