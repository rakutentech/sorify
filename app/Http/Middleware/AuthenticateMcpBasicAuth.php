<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticateMcpBasicAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Basic ')) {
            return $this->unauthorized();
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return $this->unauthorized();
        }

        [$email, $password] = explode(':', $decoded, 2);

        if (! Auth::once(['email' => $email, 'password' => $password])) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response('Unauthorized', 401)
            ->header('WWW-Authenticate', 'Basic realm="Sorify MCP"');
    }
}
