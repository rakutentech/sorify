<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('webhook_token', 64)->nullable()->unique()->after('created_by');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('playwright_proxy', 500)->nullable();
            $table->string('browser', 20)->default('chromium');
            $table->boolean('headless')->default(true);
            $table->unsignedTinyInteger('history_retention')->default(5);
            $table->unsignedInteger('timeout_ms')->default(30000);
            $table->boolean('take_screenshot')->default(true);
            $table->string('base_url', 500)->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->string('teams_webhook_url', 500)->nullable();
            $table->boolean('teams_notify_on_success')->default(false);
            $table->boolean('teams_notify_on_failure')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suites');
    }
};
