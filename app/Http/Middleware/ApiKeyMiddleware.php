<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = config('alphaforge.api_key') ?? env('ALPHAFORGE_API_KEY', '');

        if ($apiKey === '') {
            return $next($request);
        }

        $token = $request->bearerToken();

        if (! $token || ! hash_equals($apiKey, $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}