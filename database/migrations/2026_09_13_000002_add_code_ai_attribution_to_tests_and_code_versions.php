<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attribution for the *current* playwright_code: who last changed it
        // (manual | mcp | agent) and which AI model produced it (null for
        // manual edits, self-reported for MCP/REST callers).
        Schema::table('tests', function (Blueprint $table) {
            $table->string('code_source', 32)->nullable()->after('playwright_code');
            $table->string('code_ai_model', 255)->nullable()->after('code_source');
        });

        // The AI model that WROTE the code archived in this version row —
        // captured from the test row at archive time, so each historical
        // version carries the authorship of its own code.
        Schema::table('test_code_versions', function (Blueprint $table) {
            $table->string('ai_model', 255)->nullable()->after('playwright_code');
        });
    }

    public function down(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            $table->dropColumn(['code_source', 'code_ai_model']);
        });

        Schema::table('test_code_versions', function (Blueprint $table) {
            $table->dropColumn('ai_model');
        });
    }
};
