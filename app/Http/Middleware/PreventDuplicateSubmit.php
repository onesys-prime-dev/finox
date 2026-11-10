<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forms send a one-time `_submit_token`. The first successful submit consumes it;
 * a double click / replay is ignored instead of creating a second record.
 */
class PreventDuplicateSubmit
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('_submit_token');
        if (! $token || ! $request->isMethod('post')) {
            return $next($request);
        }
        $key = 'finox.submit.'.$request->user()?->id.'.'.$token;
        if (Cache::has($key)) {
            return back()->with('warning', 'That form was already submitted.');
        }

        $response = $next($request);

        $failed = $response->getStatusCode() >= 400
            || ($request->hasSession() && $request->session()->get('errors')?->any());
        if (! $failed) {
            Cache::put($key, 1, now()->addMinutes(10));
        }

        return $response;
    }
}
