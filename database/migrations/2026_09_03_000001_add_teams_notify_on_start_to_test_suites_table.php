<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->boolean('teams_notify_on_start')->default(false)->after('teams_notify_on_failure');
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropColumn('teams_notify_on_start');
        });
    }
};
