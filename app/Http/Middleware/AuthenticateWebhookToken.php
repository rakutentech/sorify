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

        if ($suite === null) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $request->attributes->set('webhook_suite', $suite);

        return $next($request);
    }
}
