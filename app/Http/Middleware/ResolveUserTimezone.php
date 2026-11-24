<?php

namespace App\Http\Middleware;

use App\Support\UserTime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membaca zona waktu pengguna dari field form `timezone` (diutamakan) atau cookie `tk_tz` yang ditulis app.js
 * dari Intl.DateTimeFormat. Zona tidak valid / kosong → config('tutorkita.default_timezone').
 */
class ResolveUserTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->input('timezone');
        if (! UserTime::isValid(is_string($candidate) ? $candidate : null)) $candidate = $request->cookie('tk_tz');
        UserTime::set(is_string($candidate) ? $candidate : null);
        view()->share('user_timezone', UserTime::tz());
        view()->share('user_timezone_abbr', UserTime::abbreviation());

        return $next($request);
    }
}
