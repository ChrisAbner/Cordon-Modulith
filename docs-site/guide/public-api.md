# The public API of a module

Other modules may only use classes that belong to a module's public API. A class is public when, in order of precedence:

1. it is marked `#[Cordon\Attributes\Internal]` → **internal**, always;
2. it is marked `#[Cordon\Attributes\PublicApi]` → public;
3. its module is configured as `open` → public (handy for a shared kernel);
4. it lives under one of the public namespaces, relative to the module: `Contracts`, `Events`, `Data`, `Enums`, `Exceptions` by default, plus the module's own `public` list → public;
5. otherwise → **internal**.

For a module `Modules\Catalog`:

| Class | Public? | Why |
|---|---|---|
| `Modules\Catalog\Contracts\ProductCatalog` | yes | `Contracts` namespace |
| `Modules\Catalog\Data\ProductData` | yes | `Data` namespace |
| `Modules\Catalog\Events\ProductDiscontinued` | yes | `Events` namespace |
| `Modules\Catalog\Models\Product` | no | internal by default |
| `Modules\Catalog\Support\PriceFormatter` with `#[PublicApi]` | yes | attribute |
| `Modules\Catalog\Contracts\LegacyCatalog` with `#[Internal]` | no | `#[Internal]` always wins |

## Marking classes

```php
namespace Modules\Catalog\Support;

use Cordon\Attributes\PublicApi;

#[PublicApi]
final class PriceFormatter
{
    // ...
}
```

The attributes have no runtime behaviour; Cordon Modulith reads them statically.

## Extra public namespaces per module

```php
// config/cordon.php
'modules' => [
    'Sales' => ['public' => ['Application\\Commands', 'Domain\\Events']],
],
```

Entries are relative to the module namespace and can be a namespace or a single class.

## Open modules

A shared kernel (value objects like `Money`, base exceptions...) can be `open`: every class in it is public.

```php
'modules' => [
    'Shared' => ['open' => true],
],
```

Keep open modules small: everything in them becomes a dependency of the whole application.

## How modules should talk

- **Contracts**: interfaces in `Contracts`, bound to an internal implementation in the module's service provider.
- **Data objects**: immutable classes in `Data` that cross the boundary instead of Eloquent models.
- **Events**: classes in `Events` that other modules listen to.

See the recipes: [expose a service](../recipes/expose-a-service), [break a cycle with an event](../recipes/break-a-cycle), [replace a cross-module relation](../recipes/cross-module-relations).
