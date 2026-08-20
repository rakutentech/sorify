<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Runs\CancelRunTool;
use App\Mcp\Tools\Runs\DeleteRunTool;
use App\Mcp\Tools\Runs\GetRunStatusTool;
use App\Mcp\Tools\Runs\GetRunTool;
use App\Mcp\Tools\Runs\TriggerRunTool;
use App\Mcp\Tools\Screenshots\GetScreenshotTool;
use App\Mcp\Tools\Screenshots\ListScreenshotsTool;
use App\Mcp\Tools\Suites\AddSuiteMemberTool;
use App\Mcp\Tools\Suites\BookmarkSuiteTool;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\DeleteSuiteScheduleTool;
use App\Mcp\Tools\Suites\DeleteSuiteTool;
use App\Mcp\Tools\Suites\GetSuiteTool;
use App\Mcp\Tools\Suites\ListBookmarkedSuitesTool;
use App\Mcp\Tools\Suites\ListSuiteMembersTool;
use App\Mcp\Tools\Suites\ListSuitesTool;
use App\Mcp\Tools\Suites\RemoveSuiteMemberTool;
use App\Mcp\Tools\Suites\UnbookmarkSuiteTool;
use App\Mcp\Tools\Suites\UpdateSuiteMemberTool;
use App\Mcp\Tools\Suites\UpdateSuiteScheduleTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Mcp\Tools\Tests\BulkCreateTestsTool;
use App\Mcp\Tools\Tests\BulkDeleteTestsTool;
use App\Mcp\Tools\Tests\CreateTestTool;
use App\Mcp\Tools\Tests\DeleteTestTool;
use App\Mcp\Tools\Tests\GetTestTool;
use App\Mcp\Tools\Tests\ListTestsTool;
use App\Mcp\Tools\Tests\ToggleTestStatusTool;
use App\Mcp\Tools\Tests\UpdateTestCodeTool;
use App\Mcp\Tools\Tests\UpdateTestTool;
use Laravel\Mcp\Server;

class SorifyServer extends Server
{
    protected string $name = 'Sorify';

    protected string $version = '1.0.0';

    protected string $instructions = 'Manage Sorify test suites, tests, runs, and screenshots — the same actions available on the Sorify dashboard.';

    protected array $tools = [
        ListSuitesTool::class,
        GetSuiteTool::class,
        CreateSuiteTool::class,
        UpdateSuiteTool::class,
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
        DeleteTestTool::class,
        BulkDeleteTestsTool::class,

        TriggerRunTool::class,
        GetRunTool::class,
        GetRunStatusTool::class,
        CancelRunTool::class,
        DeleteRunTool::class,

        ListScreenshotsTool::class,
        GetScreenshotTool::class,
    ];
}
