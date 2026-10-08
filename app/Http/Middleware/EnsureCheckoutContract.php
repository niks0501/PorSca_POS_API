<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

class EnsureCheckoutContract
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->header('X-PorSca-Contract-Version') !== 'porsca-mobile-api-v3') {
            throw new ApiException('Checkout resources require porsca-mobile-api-v3.', 409);
        }

        return $next($request);
    }
}
