<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suite's browser setting now holds a comma-separated list of Playwright
 * engines (e.g. "chromium,firefox") — each enabled engine gets its own run.
 * Existing single-browser values are already valid one-element lists, so the
 * column only needs widening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->string('browser', 100)->default('chromium')->change();
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->string('browser', 20)->default('chromium')->change();
        });
    }
};
