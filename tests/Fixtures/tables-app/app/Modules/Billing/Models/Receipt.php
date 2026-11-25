<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $table = 'order_items';
}
