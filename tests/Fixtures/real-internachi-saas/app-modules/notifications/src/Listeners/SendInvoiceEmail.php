<?php

namespace Modules\Notifications\Listeners;

use Modules\Accounts\Contracts\FindsTeams;
use Modules\Invoicing\Events\InvoiceIssued;

final class SendInvoiceEmail
{
    public function __construct(private FindsTeams $teams) {}

    public function handle(InvoiceIssued $event): void
    {
        // ...
    }
}
