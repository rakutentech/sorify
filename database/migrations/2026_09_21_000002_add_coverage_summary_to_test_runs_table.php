<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            // Filled by GenerateRunCoverageReportJob once the run has fully
            // completed: {lines: {pct,...}, functions: {...}, branches: {...}}.
            $table->json('coverage_summary')->nullable()->after('status_note');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn('coverage_summary');
        });
    }
};
