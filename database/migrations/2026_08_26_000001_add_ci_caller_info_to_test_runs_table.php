<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            // Caller IP address and User-Agent for CI-webhook-triggered runs.
            // Null for manual / MCP / scheduled runs.
            $table->string('ci_ip', 45)->nullable()->after('triggered_by_user_id');
            $table->string('ci_user_agent', 500)->nullable()->after('ci_ip');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn(['ci_ip', 'ci_user_agent']);
        });
    }
};
