<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDailySession
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {

            $token = $user->currentAccessToken();

            if (
                $token &&
                ! $token->created_at->copy()->timezone(config('app.timezone'))->isSameDay(now())
            ) {

                $token->delete();

                return response()->json([
                    'message' => 'Your session has expired. Please login again.',
                ], 401);
            }
        }

        return $next($request);
    }
}
