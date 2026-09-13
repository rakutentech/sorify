<?php

namespace App\Http\Controllers;

use App\Models\AgentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class AgentProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'profiles' => $this->profiles($request)->get()->map(fn (AgentProfile $profile) => $profile->toSafeArray()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'base_url' => ['required', 'string', 'max:2048'],
            'api_token' => ['required', 'string', 'max:8192'],
            'proxy_url' => ['nullable', 'url', 'max:2048'],
            'default_model' => ['nullable', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:8000'],
            'history_retention_days' => ['required', 'integer', 'in:'.implode(',', AgentProfile::RETENTION_DAYS)],
        ]);

        $profile = AgentProfile::create($validated + ['user_id' => $request->user()->id]);

        return response()->json(['profile' => $profile->toSafeArray()], 201);
    }

    public function update(Request $request, AgentProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'base_url' => ['required', 'string', 'max:2048'],
            'api_token' => ['nullable', 'string', 'max:8192'],
            'proxy_url' => ['nullable', 'url', 'max:2048'],
            'default_model' => ['nullable', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:8000'],
            'history_retention_days' => ['required', 'integer', 'in:'.implode(',', AgentProfile::RETENTION_DAYS)],
        ]);

        $profile->fill([
            'name' => $validated['name'],
            'base_url' => $validated['base_url'],
            // Write-only: blank keeps the stored token.
            'api_token' => $validated['api_token'] !== null && $validated['api_token'] !== ''
                ? $validated['api_token']
                : $profile->api_token,
            'proxy_url' => $validated['proxy_url'] ?? null,
            'default_model' => $validated['default_model'] ?? null,
            'system_prompt' => $validated['system_prompt'] ?? null,
            'history_retention_days' => $validated['history_retention_days'],
        ])->save();

        return response()->json(['profile' => $profile->toSafeArray()]);
    }

    public function destroy(Request $request, AgentProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile);

        $profile->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Ping the profile's /models — accepts unsaved form values so a
     * connection can be tested before saving.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if ($credentials === null) {
            return response()->json(['ok' => false, 'error' => 'Configure a base URL and token first.'], 422);
        }

        $started = microtime(true);

        try {
            $result = $this->fetchModels($credentials);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        }

        return response()->json([
            'ok' => true,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'models_count' => count($result),
        ]);
    }

    /**
     * Model list for the chat header picker.
     */
    public function models(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if ($credentials === null) {
            return response()->json(['models' => []]);
        }

        try {
            $models = $this->fetchModels($credentials);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['models' => [], 'error' => $exception->getMessage()], 502);
        }

        return response()->json(['models' => $models]);
    }

    private function authorizeProfile(Request $request, AgentProfile $profile): void
    {
        if ($profile->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    /**
     * @return array{base_url: string, token: ?string, proxy: ?string}|null
     */
    private function credentials(Request $request): ?array
    {
        $profile = null;

        if ($request->filled('profile_id')) {
            $profile = AgentProfile::query()
                ->where('user_id', $request->user()->id)
                ->find($request->input('profile_id'));

            if ($profile === null) {
                return null;
            }
        }

        // The token field is write-only: a blank value on the edit form
        // means "keep the stored one". Explicit null must therefore fall
        // back to the profile's stored credentials, not drop them — only a
        // filled input overrides.
        $baseUrl = $this->filled($request->input('base_url')) ?? $profile?->base_url;
        $token = $this->filled($request->input('api_token')) ?? $profile?->api_token;
        $proxy = $this->filled($request->input('proxy_url')) ?? $profile?->proxy_url;

        if (! $baseUrl) {
            return null;
        }

        return [
            'base_url' => AgentProfile::normalizeBaseUrl($baseUrl),
            'token' => $token,
            'proxy' => $proxy,
        ];
    }

    private function filled(mixed $value): ?string
    {
        return ($value !== null && $value !== '') ? (string) $value : null;
    }

    /**
     * @param  array{base_url: string, token: ?string, proxy: ?string}  $credentials
     * @return list<string>
     */
    private function fetchModels(array $credentials): array
    {
        $request = Http::withOptions([
            'connect_timeout' => 10,
            'timeout' => 15,
        ]);

        if ($credentials['proxy'] !== null) {
            $request->withOptions(['proxy' => $credentials['proxy']]);
        }

        if ($credentials['token'] !== null) {
            $request->withHeader('Authorization', 'Bearer '.$credentials['token']);
        }

        $body = $request->get($credentials['base_url'].'/models')->json();

        return collect($body['data'] ?? [])
            ->pluck('id')
            ->filter(fn ($id) => is_string($id))
            ->values()
            ->all();
    }

    private function profiles(Request $request): object
    {
        return AgentProfile::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name');
    }
}
