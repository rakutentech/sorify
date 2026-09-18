<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per agent turn while it runs: written when the turn starts
        // (live SSE request or night-mode queue job), updated as it
        // progresses, closed when it finishes. Powers the admin
        // "running AI agents" page — including the ability to stop a turn,
        // via cancel_requested_at (checked by the turn loop each step).
        Schema::create('agent_turns', function (Blueprint $table) {
            // The turn-opening user message's id (matches agent_messages.turn_id).
            $table->id();
            $table->foreignId('conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 8); // live | night
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();

            $table->index(['finished_at', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_turns');
    }
};
