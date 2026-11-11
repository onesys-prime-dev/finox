<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flash messages live for "the next request". Background calls (payee search, duplicate checks, …) are made by
 * the page itself, so one of them could swallow the message meant for the page the user is being redirected to.
 * Plain AJAX calls therefore keep the flash data for the real page visit.
 */
class KeepFlashOnAjax
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && $request->ajax() && ! $request->header('X-Inertia')) {
            $request->session()->reflash();
        }

        return $response;
    }
}
