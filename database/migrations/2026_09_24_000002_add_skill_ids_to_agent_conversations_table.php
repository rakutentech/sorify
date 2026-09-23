<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skills attached to a conversation — the ids of the chatting
        // user's skills whose markdown content is injected into every
        // turn's system prompt.
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->json('skill_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropColumn('skill_ids');
        });
    }
};
