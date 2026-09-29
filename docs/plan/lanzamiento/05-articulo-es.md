# Cómo impedir que tu módulo Billing use los modelos de Catalog

*Borrador para dev.to. Código de `tests/Fixtures/namespace-app` del repositorio de Cordon Modulith.*

Dividiste tu aplicación Laravel en módulos: `app/Modules/Billing`, `app/Modules/Catalog`, `app/Modules/Shared`. Cada uno con sus modelos, servicios y eventos. Hasta que llega esto en un pull request:

```php
namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\ProductCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Money;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(Product $product): Money
    {
        return new Money($product->price);
    }
}
```

Funciona. Pasa la revisión. Y ahora Billing depende de la tabla de Catalog a través de su modelo Eloquent.

## Por qué importa

Un límite de módulo es una promesa: "puedes cambiar cualquier cosa dentro mientras la API pública no cambie". En cuanto otros módulos usan tus modelos, ya no puedes renombrar una columna, dividir una tabla o cambiar cómo se guardan los precios sin tocarlos. Las carpetas siguen diciendo "módulos", pero el código ya no.

## Haz explícita la API pública

Una convención sencilla cubre la mayoría de casos. Otros módulos pueden usar:

- `Contracts`: interfaces, enlazadas a implementaciones internas en el service provider del módulo;
- `Data`: objetos inmutables que cruzan el límite en lugar de los modelos;
- `Events`: lo que pasó en el módulo, para que otros reaccionen;
- `Enums` y `Exceptions`.

Todo lo demás (modelos, servicios, jobs, acciones) es interno. Las excepciones a la convención se marcan con un atributo:

```php
namespace App\Modules\Catalog\Support;

use Cordon\Attributes\PublicApi;

#[PublicApi]
final class PriceFormatter
{
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
```

## Compruébalo en cada pull request

Las convenciones solo funcionan si algo las verifica. [Cordon Modulith](https://github.com/ChrisAbner/Cordon-Modulith) es un paquete de desarrollo que analiza tus módulos de forma estática (no carga ni ejecuta nada) e informa de las violaciones de límites:

```bash
composer require --dev chrisabner/cordon-modulith
php artisan cordon:verify
```

```text
Cordon analysed 9 files in 3 modules (7 cross-module references).

x [internal_access] app/Modules/Billing/Services/CheckoutService.php:13
  Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).

x [internal_access] app/Modules/Billing/Services/InvoiceRenderer.php:10
  Module [Billing] uses App\Modules\Catalog\Contracts\LegacyCatalog, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).

x [cycles]
  Modules [Billing, Catalog] form a dependency cycle: Billing -> Catalog -> Billing. Break it by inverting one dependency, for example with an event or a contract.

3 boundary violations.
```

`LegacyCatalog` está en `Contracts`, pero Catalog lo marcó `#[Internal]` porque va a desaparecer: el atributo siempre gana.

El value object `Money` no da problemas porque `Shared` está configurado como módulo abierto (un shared kernel):

```php
// config/cordon.php
'modules' => [
    'Shared' => ['open' => true],
],
```

## Arréglalo

Billing necesita un precio, no un modelo. Catalog ya publica un contrato; que devuelva un objeto de datos y úsalo:

```php
namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\ProductCatalog;
use App\Modules\Shared\Money;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(int $productId): Money
    {
        return new Money($this->catalog->find($productId)->priceInCents);
    }
}
```

El ciclo es otro tipo de problema. Catalog escucha el evento `InvoicePaid` de Billing para actualizar el stock, mientras Billing usa el contrato de Catalog para poner precio. Escuchar un evento también es una dependencia, así que los dos módulos dependen el uno del otro. Una de las dos flechas tiene que desaparecer: por ejemplo, Billing recibe los precios de quien lo llama en lugar de preguntar a Catalog, o Catalog expone un contrato `ReservesStock` que llama el checkout. La herramienta te dice que el ciclo existe y su camino más corto; qué dependencia invertir es una decisión de diseño.

## Adoptarlo en un proyecto real

Nadie arregla 300 violaciones en un pull request. Regístralas en un baseline, haz commit, y solo fallarán las nuevas:

```bash
php artisan cordon:verify --generate-baseline
```

Después, un módulo por sprint: `php artisan cordon:verify --no-baseline --module=Billing`.

## Dónde más funciona

- Pest: `expect('Billing')->toRespectBoundaries();`
- PHPStan: la regla `internal_access` aparece en tu editor.
- Documentación: `php artisan cordon:docs` genera diagramas Mermaid de las dependencias reales y un inventario de eventos.

## Lo que no es

No sustituye a Deptrac (muy bueno para capas) ni a `arch()` de Pest (muy bueno para convenciones dentro de un módulo); se centra en las flechas entre módulos. El análisis estático también tiene límites: los nombres de clase en strings y los tipos que solo están en docblocks no se detectan, a propósito, para evitar falsos positivos.

Si mantienes un monolito modular en Laravel, me gustaría saber cómo proteges hoy los límites y dónde este enfoque no encajaría.
