<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Whilesmart\Entitlements\Traits\HasEntitlements;

class Owner extends Model
{
    use HasEntitlements;

    protected $table = 'owners';

    protected $guarded = [];
}
