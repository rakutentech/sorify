<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Night mode: run agent turns in a queue job instead of the SSE
        // request, so they keep going when the browser window closes.
        // Bounded by a user-selected max run time in minutes.
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->boolean('night_mode')->default(false)->after('context');
            $table->unsignedSmallInteger('night_max_run_minutes')->default(10)->after('night_mode');
        });

        // One row per SSE event of a night-mode turn, written by the job
        // as the turn progresses. The UI replays them (cursor = seq) to
        // render the turn live, or to catch up after a reconnect.
        Schema::create('agent_turn_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            // The turn-opening user message's id (matches agent_messages.turn_id).
            $table->foreignId('turn_id');
            $table->unsignedInteger('seq');
            $table->string('event', 32); // step | delta | tool_start | tool_result | waiting | done | error
            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique(['turn_id', 'seq']);
            $table->index(['conversation_id', 'turn_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_turn_events');

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropColumn(['night_mode', 'night_max_run_minutes']);
        });
    }
};
