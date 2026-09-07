<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who set up each integration — used by the GitHub App access-list
        // sweep to tell the removed user's integrations apart from ones a
        // still-whitelisted member created. Null = created before the
        // feature existed (treated as the removing user's in their suites).
        Schema::table('test_suite_integrations', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('test_suite_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
