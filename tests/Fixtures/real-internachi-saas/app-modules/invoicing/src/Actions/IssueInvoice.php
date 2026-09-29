<?php

namespace Modules\Invoicing\Actions;

use Modules\Accounts\Contracts\FindsTeams;
use Modules\Invoicing\Events\InvoiceIssued;
use Modules\Invoicing\Models\Invoice;

final class IssueInvoice
{
    public function __construct(private FindsTeams $teams) {}

    public function __invoke(int $teamId): Invoice
    {
        $team = $this->teams->find($teamId);
        $invoice = Invoice::create(['team_id' => $team->id]);

        InvoiceIssued::dispatch($invoice->id);

        return $invoice;
    }
}
