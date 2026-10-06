<?php

namespace Whilesmart\EntitlementsCashier\Http\Controllers;

use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

class WebhookController extends \Laravel\Cashier\Http\Controllers\WebhookController
{
    public function __construct()
    {
        abort_unless(filled(config('cashier.webhook.secret')), 503, 'Billing webhook authentication is not configured.');
        $this->middleware(VerifyWebhookSignature::class);
    }
}
