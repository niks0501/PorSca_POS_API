<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('app.api_token');
        $provided = (string) $request->bearerToken();

        if ($configured === '' || $provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'A valid Bearer token is required.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
