<?php

namespace Whilesmart\EntitlementsCashier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireWebhookSecret
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(filled(config('cashier.webhook.secret')), 503, 'Billing webhook authentication is not configured.');

        return $next($request);
    }
}
