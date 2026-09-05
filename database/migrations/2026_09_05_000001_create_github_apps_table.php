<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multiple GitHub Apps (public github.com + one or more GitHub
     * Enterprise instances) can be configured in the dashboard (Admin →
     * GitHub Apps; stored encrypted in the database). Each row is one app
     * serving both sign-in (OAuth/GitHub App user flow) and Actions
     * dispatch (installation tokens). github_id is only unique PER app
     * (user 123 on github.com is not user 123 on a GHES instance).
     */
    public function up(): void
    {
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Empty means public github.com.
            $table->string('base_url', 255)->default('');
            $table->string('client_id', 100);
            $table->text('client_secret');
            $table->string('redirect_uri', 500)->nullable();
            $table->string('proxy', 500)->nullable();
            // Actions dispatch (optional — an app may be sign-in only).
            $table->string('app_id', 50)->nullable();
            $table->text('private_key')->nullable();
            // Purpose switches: whether the app is offered for sign-in /
            // usable for Actions dispatch (credentials permitting).
            $table->boolean('sign_in_enabled')->default(true);
            $table->boolean('actions_enabled')->default(true);
            $table->timestamps();

            $table->unique(['base_url', 'client_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('github_app_id')->nullable()->after('github_id')->constrained('github_apps')->nullOnDelete();
        });

        // github_id is only unique per app now.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['github_id']);
            $table->unique(['github_app_id', 'github_id']);
        });

        Schema::table('test_suite_integrations', function (Blueprint $table) {
            $table->foreignId('github_app_id')->nullable()->after('type')->constrained('github_apps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('test_suite_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_app_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['github_app_id', 'github_id']);
            $table->unique(['github_id']);
            $table->dropConstrainedForeignId('github_app_id');
        });

        Schema::dropIfExists('github_apps');
    }
};
