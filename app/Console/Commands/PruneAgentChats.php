<?php

namespace App\Console\Commands;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use Illuminate\Console\Command;

class PruneAgentChats extends Command
{
    protected $signature = 'sorify:prune-agent-chats';

    protected $description = 'Delete agent chat turns older than each profile\'s configured retention window';

    public function handle(): int
    {
        $deleted = 0;

        // Per-profile retention: each profile's conversations keep messages
        // for the profile's retention window.
        AgentProfile::query()
            ->chunkById(200, function ($profiles) use (&$deleted) {
                foreach ($profiles as $profile) {
                    $cutoff = now()->subDays($profile->history_retention_days ?: 30);

                    $conversationIds = AgentConversation::query()
                        ->where('agent_profile_id', $profile->id)
                        ->pluck('id');

                    $deleted += AgentMessage::query()
                        ->whereIn('conversation_id', $conversationIds)
                        ->where('created_at', '<', $cutoff)
                        ->delete();
                }
            });

        // Conversations without a profile (deleted profile) and any strays:
        // hard one-year cap.
        $deleted += AgentMessage::query()
            ->where('created_at', '<', now()->subDays(365))
            ->delete();

        $this->info("Pruned {$deleted} agent chat messages.");

        return self::SUCCESS;
    }
}
