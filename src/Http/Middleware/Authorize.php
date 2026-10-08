<?php

namespace Jiannius\Backup\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Reject the request with a 404 when the UI is disabled, then with a 403
     * unless backup()->authorize() allows it. The enabled check also covers
     * Livewire update requests, which re-run this middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('backup.ui.enabled'), 404);

        abort_unless(backup()->authorize($request), 403);

        return $next($request);
    }
}
