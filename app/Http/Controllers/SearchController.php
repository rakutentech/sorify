<?php

namespace App\Http\Controllers;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\AgentConversation;
use App\Models\Test;
use App\Models\TestRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search for the command palette (Cmd/Ctrl+K).
 *
 * One request, four small result groups — suites, tests, runs and the
 * signed-in user's agent conversations — scoped exactly like the list
 * pages: suites via suite membership (can_view), tests/runs through
 * their suite, conversations strictly per-user.
 */
class SearchController extends Controller
{
    use AuthorizesSuiteAccess;

    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->toString());

        if (mb_strlen($query) < 2) {
            return response()->json($this->emptyResults());
        }

        $like = "%{$query}%";
        $isAdmin = $request->user()->is_admin;

        // Non-admins only see tests and runs of suites they can view.
        $scopeVisibleSuite = function ($builder) use ($isAdmin, $request) {
            if (! $isAdmin) {
                $builder->whereHas('members', function ($membership) use ($request) {
                    $membership->where('users.id', $request->user()->id)
                        ->where('test_suite_user.can_view', true);
                });
            }
        };

        $suites = $this->visibleSuitesQuery()
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'description']);

        $tests = Test::query()
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like);
            })
            ->whereHas('testSuite', $scopeVisibleSuite)
            ->with('testSuite:id,name')
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'test_suite_id']);

        $runs = TestRun::query()
            ->where(function ($q) use ($like, $query) {
                $q->whereHas('testSuite', function ($suite) use ($like) {
                    $suite->where('name', 'like', $like);
                });

                // A run's most searchable attribute is its id — "238" should
                // jump straight to that run.
                if (ctype_digit($query)) {
                    $q->orWhere('id', (int) $query);
                }
            })
            ->whereHas('testSuite', $scopeVisibleSuite)
            ->with('testSuite:id,name')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'status', 'created_at', 'test_suite_id']);

        $conversations = AgentConversation::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('context', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'page_name', 'updated_at']);

        return response()->json([
            'suites' => $suites->map(fn ($suite) => [
                'id' => $suite->id,
                'name' => $suite->name,
                'description' => $suite->description,
            ]),
            'tests' => $tests->map(fn ($test) => [
                'id' => $test->id,
                'name' => $test->name,
                'suite_id' => $test->test_suite_id,
                'suite_name' => $test->testSuite?->name,
            ]),
            'runs' => $runs->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'created_at' => $run->created_at?->toISOString(),
                'suite_id' => $run->test_suite_id,
                'suite_name' => $run->testSuite?->name,
            ]),
            'conversations' => $conversations->map(fn ($conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'page_name' => $conversation->page_name,
                'updated_at' => $conversation->updated_at?->toISOString(),
            ]),
        ]);
    }

    private function emptyResults(): array
    {
        return ['suites' => [], 'tests' => [], 'runs' => [], 'conversations' => []];
    }
}
