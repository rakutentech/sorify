<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            // Human-readable explanation for the current status, e.g. why a
            // run is still pending (waiting on a pre-run integration) or why
            // it failed before any test executed.
            $table->string('status_note', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn('status_note');
        });
    }
};
