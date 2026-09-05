<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suite_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained()->cascadeOnDelete();
            // Integration type, e.g. 'github_action'. Future types (slack,
            // pagerduty, jenkins, http) slot in without schema changes.
            $table->string('type', 50);
            // Set when an integration is force-disabled (e.g. its GitHub App
            // was deleted by an admin) — shown as a warning on the suite page.
            // The github_app_id FK lives in the create_github_apps migration.
            $table->string('disabled_note', 500)->nullable();
            $table->string('label', 100)->nullable();
            // Type-specific settings (repository, workflow, ref, inputs...).
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('trigger_before')->default(false);
            $table->boolean('trigger_after')->default(false);
            $table->timestamps();

            $table->index(['test_suite_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suite_integrations');
    }
};
