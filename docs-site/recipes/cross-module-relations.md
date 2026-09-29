# Replace a cross-module Eloquent relation

**Problem:** Eloquent makes it easy to relate models across modules:

```php
namespace Modules\Invoicing\Models;

use Modules\Accounts\Models\Team;

class Invoice extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);   // internal_access
    }
}
```

Every `$invoice->team->...` in Invoicing now depends on the `teams` table and the `Team` model. Renaming a column in Accounts breaks Invoicing.

## Keep the foreign key, ask the owner

Keep `team_id` on the invoice, remove the relation and load what you need through the owning module's public API:

```php
namespace Modules\Accounts\Contracts;

use Modules\Accounts\Data\TeamData;

interface FindsTeams
{
    public function find(int $id): TeamData;

    /**
     * @param  list<int>  $ids
     * @return array<int, TeamData>  keyed by id
     */
    public function findMany(array $ids): array;
}
```

```php
namespace Modules\Invoicing\Actions;

use Modules\Accounts\Contracts\FindsTeams;
use Modules\Invoicing\Models\Invoice;

final class ListInvoices
{
    public function __construct(private FindsTeams $teams) {}

    public function __invoke(): array
    {
        $invoices = Invoice::latest()->limit(50)->get();
        $teams = $this->teams->findMany($invoices->pluck('team_id')->unique()->all());

        return $invoices->map(fn (Invoice $invoice) => [
            'number' => $invoice->number,
            'team' => $teams[$invoice->team_id]->name ?? null,
        ])->all();
    }
}
```

`findMany()` avoids N+1 queries without a relation.

## What about joins and reports?

Reporting queries that join tables of several modules are a legitimate need. Options:

- a read model owned by a reporting module and fed by events;
- a query in the owning module that returns data objects;
- accepting the dependency for a reporting module and declaring it explicitly in `depends_on`, with the model marked `#[PublicApi]` only if the owner agrees to keep it stable.
