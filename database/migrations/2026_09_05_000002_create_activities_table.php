<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only activity feed log. One row per user-visible event
     * (runs, suite/test CRUD, membership changes, new users, …).
     *
     * `payload` only ever holds whitelisted, non-sensitive data — the
     * ActivityLogger service drops anything not on its per-type key list
     * before writing, so suite variables, cookie values, webhook URLs and
     * CI caller info can never leak into the feed.
     *
     * Rows tied to a suite are cascade-deleted with it, so a deleted
     * suite's history disappears rather than dangling.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('suite_id')->nullable()->constrained('test_suites')->cascadeOnDelete();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->index('type');
            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
