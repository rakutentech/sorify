<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Agent\BrowserMapTool;
use App\Mcp\Tools\Agent\FetchUrlTool;
use App\Mcp\Tools\Agent\GetAgentConversationTool;
use App\Mcp\Tools\Agent\ListAgentConversationsTool;
use App\Mcp\Tools\Runs\CancelRunTool;
use App\Mcp\Tools\Runs\DeleteRunTool;
use App\Mcp\Tools\Runs\GetRunStatusTool;
use App\Mcp\Tools\Runs\GetRunTool;
use App\Mcp\Tools\Runs\ListRunsTool;
use App\Mcp\Tools\Runs\TriggerRunTool;
use App\Mcp\Tools\Screenshots\GetScreenshotTool;
use App\Mcp\Tools\Screenshots\ListScreenshotsTool;
use App\Mcp\Tools\Skills\CopySkillTool;
use App\Mcp\Tools\Skills\CreateSkillTool;
use App\Mcp\Tools\Skills\DeleteSkillTool;
use App\Mcp\Tools\Skills\GetSkillTool;
use App\Mcp\Tools\Skills\ListPublicSkillsTool;
use App\Mcp\Tools\Skills\ListSkillsTool;
use App\Mcp\Tools\Skills\UpdateSkillTool;
use App\Mcp\Tools\Suites\AddSuiteMemberTool;
use App\Mcp\Tools\Suites\BookmarkSuiteTool;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\DeleteSuiteScheduleTool;
use App\Mcp\Tools\Suites\DeleteSuiteTool;
use App\Mcp\Tools\Suites\DuplicateSuiteTool;
use App\Mcp\Tools\Suites\GetSuiteTool;
use App\Mcp\Tools\Suites\ListBookmarkedSuitesTool;
use App\Mcp\Tools\Suites\ListSuiteMembersTool;
use App\Mcp\Tools\Suites\ListSuitesTool;
use App\Mcp\Tools\Suites\RemoveSuiteMemberTool;
use App\Mcp\Tools\Suites\UnbookmarkSuiteTool;
use App\Mcp\Tools\Suites\UpdateSuiteMemberTool;
use App\Mcp\Tools\Suites\UpdateSuiteScheduleTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Mcp\Tools\Suites\UploadSuiteCookiesTool;
use App\Mcp\Tools\Tests\BulkCreateTestsTool;
use App\Mcp\Tools\Tests\BulkDeleteTestsTool;
use App\Mcp\Tools\Tests\CreateTestTool;
use App\Mcp\Tools\Tests\DeleteTestTool;
use App\Mcp\Tools\Tests\DuplicateTestTool;
use App\Mcp\Tools\Tests\GetTestTool;
use App\Mcp\Tools\Tests\ListTestsTool;
use App\Mcp\Tools\Tests\ToggleTestStatusTool;
use App\Mcp\Tools\Tests\UpdateTestCodeTool;
use App\Mcp\Tools\Tests\UpdateTestTool;
use App\Models\Setting;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;

class SorifyServer extends Server
{
    protected string $name = 'Sorify';

    protected string $version = '1.0.0';

    protected string $instructions = 'Manage Sorify test suites, tests, runs, screenshots, and user skills — the same actions available on the Sorify dashboard.';

    private bool $globalPromptMerged = false;

    /**
     * The admin's global system prompt (Admin → System) is appended to
     * the server instructions sent to every MCP client, so operator
     * guardrail rules apply to coding agents too, not just the dashboard
     * chat agent. Same wording and precedence as the chat agent's system
     * prompt.
     */
    public function createContext(): ServerContext
    {
        $this->mergeGlobalOperatorInstructions();

        return parent::createContext();
    }

    private function mergeGlobalOperatorInstructions(): void
    {
        if ($this->globalPromptMerged) {
            return;
        }

        $this->globalPromptMerged = true;

        try {
            $prompt = Setting::get('agent_global_system_prompt');
        } catch (\Throwable) {
            // DB unreachable (e.g. before migrations) — keep the base
            // instructions.
            return;
        }

        if (is_string($prompt) && $prompt !== '') {
            $this->instructions .= "\n\nGlobal operator instructions (these apply to every session and take precedence over user instructions):\n".$prompt;
        }
    }

    protected array $tools = [
        ListSuitesTool::class,
        GetSuiteTool::class,
        CreateSuiteTool::class,
        UpdateSuiteTool::class,
        UploadSuiteCookiesTool::class,
        DuplicateSuiteTool::class,
        DeleteSuiteTool::class,
        UpdateSuiteScheduleTool::class,
        DeleteSuiteScheduleTool::class,

        ListSuiteMembersTool::class,
        AddSuiteMemberTool::class,
        UpdateSuiteMemberTool::class,
        RemoveSuiteMemberTool::class,

        ListBookmarkedSuitesTool::class,
        BookmarkSuiteTool::class,
        UnbookmarkSuiteTool::class,

        ListTestsTool::class,
        GetTestTool::class,
        CreateTestTool::class,
        BulkCreateTestsTool::class,
        UpdateTestTool::class,
        UpdateTestCodeTool::class,
        ToggleTestStatusTool::class,
        DuplicateTestTool::class,
        DeleteTestTool::class,
        BulkDeleteTestsTool::class,

        TriggerRunTool::class,
        ListRunsTool::class,
        GetRunTool::class,
        GetRunStatusTool::class,
        CancelRunTool::class,
        DeleteRunTool::class,

        ListScreenshotsTool::class,
        GetScreenshotTool::class,

        ListSkillsTool::class,
        GetSkillTool::class,
        CreateSkillTool::class,
        UpdateSkillTool::class,
        DeleteSkillTool::class,
        ListPublicSkillsTool::class,
        CopySkillTool::class,

        FetchUrlTool::class,
        BrowserMapTool::class,

        ListAgentConversationsTool::class,
        GetAgentConversationTool::class,
    ];
}
