<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-GitHub-App access list: when non-empty, only these users (and
        // admins) may add or edit github_action integrations that dispatch
        // as the app. An empty list keeps the previous everyone-with-edit-
        // rights behavior.
        Schema::create('github_app_allowed_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['github_app_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_app_allowed_users');
    }
};
