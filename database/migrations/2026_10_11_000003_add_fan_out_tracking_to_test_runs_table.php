<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-browser fan-out: every run created by one trigger shares a
 * fan_out_id, so notifications can be consolidated per trigger — one
 * "started" message and one combined result message per group instead of
 * one pair per browser. group_notified_at is the claim marker that makes
 * the combined completion notification fire exactly once per group (the
 * last finalizing run stamps all siblings inside a row lock).
 *
 * Runs without a fan_out_id (single-engine triggers predating this, or
 * rows created outside the run service) keep the per-run notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->string('fan_out_id', 36)->nullable()->index()->after('browser');
            $table->timestamp('group_notified_at')->nullable()->after('fan_out_id');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn(['fan_out_id', 'group_notified_at']);
        });
    }
};
