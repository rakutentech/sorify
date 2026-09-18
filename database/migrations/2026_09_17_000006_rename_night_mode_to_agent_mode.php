<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Night mode" is now "Agent mode": the per-conversation toggle that
 * enables the full agent (tools, background execution, max run time).
 * The separate Ask / Agent per-message toggle is gone — a conversation
 * is either in Agent mode or plain Ask mode. agent_turns.mode now
 * records the turn's actual mode ('ask' / 'agent') instead of the old
 * live / night execution detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->renameColumn('night_mode', 'agent_mode');
            $table->renameColumn('night_max_run_minutes', 'agent_max_run_minutes');
        });

        DB::table('agent_turns')->whereIn('mode', ['live', 'night'])->update(['mode' => 'agent']);
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->renameColumn('agent_mode', 'night_mode');
            $table->renameColumn('agent_max_run_minutes', 'night_max_run_minutes');
        });

        DB::table('agent_turns')->where('mode', 'agent')->update(['mode' => 'night']);
    }
};
