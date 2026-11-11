<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Orders\Services\Ledger;

// Not a listener: handle() receives a service through dependency injection.
final class SyncLedger
{
    public function handle(Ledger $ledger): void
    {
        $ledger->sync();
    }
}
