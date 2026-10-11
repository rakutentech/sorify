<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each run records the Playwright engine it executed against — a suite with
 * multiple browsers enabled fans out into one run per browser, and the run
 * label surfaces which one was used. Runs created before this (and runs of
 * single-browser suites) may also stay null; the runner falls back to the
 * suite's primary browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->string('browser', 20)->nullable()->after('test_suite_id');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn('browser');
        });
    }
};
