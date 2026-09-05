<?php

namespace App\Http\Middleware;

use App\Models\TestSuite;
use Closure;
use Illuminate\Http\Request;

class AuthenticateWebhookToken
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = (string) $request->route('token');

        $suite = $token !== '' ? TestSuite::where('webhook_token', $token)->first() : null;

        if ($suite === null && $token !== '') {
            // Not the current token — it may be one of the suite's superseded
            // tokens, which stay active until explicitly deleted. The LIKE is
            // only a row prefilter; the exact in-array check is authoritative.
            $suite = TestSuite::query()
                ->whereNotNull('previous_webhook_tokens')
                ->where('previous_webhook_tokens', 'like', '%'.$token.'%')
                ->get()
                ->first(fn (TestSuite $s) => $s->hasPreviousWebhookToken($token));
        }

        if ($suite === null) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $request->attributes->set('webhook_suite', $suite);

        return $next($request);
    }
}
