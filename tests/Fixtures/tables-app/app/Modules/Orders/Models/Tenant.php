<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    public const TABLE = 'tenants';

    protected $table = self::TABLE;
}
