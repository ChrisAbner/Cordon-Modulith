# Krayin CRM

[Krayin CRM](https://github.com/krayin/laravel-crm) is an open source CRM built by Webkul, the team behind Bagisto. It uses the same layout: 20 packages under `packages/Webkul`, each with its own service provider, Concord model proxies and repositories. The packages cover contacts (`Contact`), the sales pipeline (`Lead`, `Quote`, `Product`), activities and email (`Activity`, `Email`, `EmailTemplate`), automation (`Automation`, `WebForm`) and a shared kernel (`Core`, `DataGrid`).

**Analysed:** commit [`ed6deeb`](https://github.com/krayin/laravel-crm/tree/ed6deebfcb8c616eaf352c893a10a6d8e666fc6e) on the `2.2` branch, November 2026, with Cordon 0.4.0 and PHP 8.4.

## Results

| | Default config | Public API declared |
|---|---|---|
| Modules | 20 | 20 |
| Files analysed | 632 | 632 |
| Cross-module references | 467 | 467 |
| `internal_access` | 368 | 52 |
| `cycles` | 107 dependencies in 1 group of 19 modules | 107 dependencies in 1 group of 19 modules |
| Time | 0.8 s | 0.8 s |

We read 25 reports at random by hand. All 25 pointed at a real reference.

## Configuration

Krayin shares Bagisto's conventions, so we declared the same things: the shared kernel, repositories, facades and the model proxies.

```php
// config/cordon.php
'resolver' => 'namespace',

'resolvers' => [
    'namespace' => ['path' => 'packages/Webkul', 'namespace' => 'Webkul'],
],

'host' => ['paths' => []],

'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions', 'Repositories', 'Facades'],

'modules' => [
    'Core' => ['open' => true],
    'DataGrid' => ['open' => true],
    // and every Models\*Proxy class, listed per module, for example:
    'Lead' => ['public' => ['Models\\LeadProxy', 'Models\\PipelineProxy', 'Models\\ProductProxy', 'Models\\SourceProxy', 'Models\\StageProxy', 'Models\\TypeProxy']],
],
```

The first report was mostly configuration. The base repository `Core\Eloquent\Repository` alone had 40 references, and the repositories of `Attribute`, `Contact` and `Lead` another 81. Declaring the kernel, the repositories and the proxies removed 316 of the 368 reports.

## What remains

The remaining 52 reports are small and easy to read:

| Class | References | From modules |
|---|---|---|
| `Attribute\Traits\CustomAttribute` | 6 | 5 |
| `Activity\Traits\LogsActivity` | 5 | 5 |
| `Admin\Notifications\Common` | 4 | 1 |
| `Attribute\Models\AttributeValue` | 3 | 2 |

The two traits are extension points: every entity with custom attributes uses `CustomAttribute`, and every entity that logs changes uses `LogsActivity`. That is intended, so the fix is to declare them public. `Automation` sending a mail class that lives in `Admin` is the same pattern as `Sales` sending a `Shop` mail in Bagisto.

## A cycle between two classes

19 of the 20 packages belong to one group of mutually dependent modules, with 25 direct back-and-forth pairs. The smallest one fits in two files:

```php
// packages/Webkul/Attribute/src/Models/AttributeValue.php
use Webkul\Activity\Traits\LogsActivity;

// packages/Webkul/Activity/src/Traits/LogsActivity.php
use Webkul\Attribute\Contracts\AttributeValue;
use Webkul\Attribute\Repositories\AttributeValueRepository;
```

The activity log knows about attribute values, and attribute values log activity. Declaring the trait public makes the access legal, but the cycle stays: `Attribute` and `Activity` still cannot change on their own.
