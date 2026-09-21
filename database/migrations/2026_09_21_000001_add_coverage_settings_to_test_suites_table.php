<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            // Browser JavaScript coverage collection (Playwright Coverage API,
            // Chromium only). When enabled, runs collect per-test V8 coverage
            // that is merged into an HTML report + lcov.info after the run.
            $table->boolean('collect_coverage')->default(false)->after('take_screenshot');
            // Optional regular expression tested against script URLs; only
            // matching scripts are included in coverage (e.g. the app's
            // bundle URL, excluding third-party/vendor scripts).
            $table->string('coverage_url_filter')->nullable()->after('collect_coverage');
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropColumn(['collect_coverage', 'coverage_url_filter']);
        });
    }
};
