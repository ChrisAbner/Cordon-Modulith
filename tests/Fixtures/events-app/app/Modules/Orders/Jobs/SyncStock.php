<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Billing\Contracts\Mailer;

final class SyncStock
{
    public function handle(Mailer $mailer): void {}
}
