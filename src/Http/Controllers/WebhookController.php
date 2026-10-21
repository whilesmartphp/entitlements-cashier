<?php

namespace Whilesmart\EntitlementsCashier\Http\Controllers;

use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Whilesmart\EntitlementsCashier\Http\Middleware\RequireWebhookSecret;

class WebhookController extends \Laravel\Cashier\Http\Controllers\WebhookController
{
    public function __construct()
    {
        $this->middleware([RequireWebhookSecret::class, VerifyWebhookSignature::class]);
    }
}
