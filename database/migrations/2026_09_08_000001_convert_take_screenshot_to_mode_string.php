<?php

use App\Support\ScreenshotMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tinyint → varchar is a safe cast on every driver; the legacy
        // 0/1 values are mapped onto mode strings afterwards.
        Schema::table('test_suites', function (Blueprint $table) {
            $table->string('take_screenshot', 20)->default(ScreenshotMode::ENABLED)->change();
        });

        foreach (DB::table('test_suites')->get(['id', 'take_screenshot']) as $suite) {
            if (in_array($suite->take_screenshot, ScreenshotMode::ALL, true)) {
                continue;
            }

            DB::table('test_suites')->where('id', $suite->id)->update([
                'take_screenshot' => filter_var($suite->take_screenshot, FILTER_VALIDATE_BOOL)
                    ? ScreenshotMode::ENABLED
                    : ScreenshotMode::DISABLED,
            ]);
        }
    }

    public function down(): void
    {
        // Collapse the modes back to '0'/'1' BEFORE the type change —
        // MySQL strict mode rejects converting strings like 'enabled'
        // to a tinyint, while '0'/'1' cast cleanly.
        DB::table('test_suites')->update([
            'take_screenshot' => DB::raw("CASE WHEN take_screenshot = '".ScreenshotMode::DISABLED."' THEN '0' ELSE '1' END"),
        ]);

        Schema::table('test_suites', function (Blueprint $table) {
            $table->boolean('take_screenshot')->default(true)->change();
        });
    }
};
