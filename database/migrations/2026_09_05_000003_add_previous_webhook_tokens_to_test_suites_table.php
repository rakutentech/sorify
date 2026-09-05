<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            // Superseded webhook tokens: kept active (authenticating like the
            // current one) until the user explicitly deletes them, so CI
            // pipelines wired to an old URL survive a regenerate.
            $table->json('previous_webhook_tokens')->nullable()->after('webhook_token');
        });
    }

    public function down(): void
    {
        Schema::table('test_suites', function (Blueprint $table) {
            $table->dropColumn('previous_webhook_tokens');
        });
    }
};
