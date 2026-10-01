<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Reject a token whose account has been deactivated.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_active) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'This account is not active.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
