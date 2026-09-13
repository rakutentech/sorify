<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replace the earlier per-user agent_settings / suite-scoped
        // agent_messages tables with the profiles + conversations model.
        // (dropIfExists also cleans up dev databases that ran the old ones.)
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_settings');
        Schema::dropIfExists('agent_profiles');

        // A named OpenAI-compatible endpoint configuration. A user can own
        // several (e.g. one for OpenAI, one for an internal vLLM).
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('base_url');
            $table->text('api_token');
            $table->string('proxy_url')->nullable();
            $table->string('default_model')->nullable();
            $table->text('system_prompt')->nullable();
            $table->unsignedSmallInteger('history_retention_days')->default(30);
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        // A chat thread with the agent. Born on a page (url + name) with a
        // user-editable context block the agent receives as background.
        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120)->default('New chat');
            $table->string('page_url', 500)->nullable();
            $table->string('page_name', 100)->nullable();
            $table->text('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            // A turn is opened by a user message; `turn_id` holds the id of
            // that opening message so every assistant/tool row of the turn
            // can be pruned together (tool rows must never outlive their
            // parent assistant tool_calls).
            $table->foreignId('turn_id')->index();
            $table->foreignId('conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->string('role', 16); // user | assistant | tool
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'turn_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_profiles');
    }
};
