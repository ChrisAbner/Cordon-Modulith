# Guion de vídeo (3 minutos)

Brief 03D. Narración en inglés (audiencia global); las indicaciones de escena están en español. Grabar en una terminal a 120 columnas con fuente grande y el editor al lado. Usar la app de `tests/Fixtures/real-nwidart-shop` (o la app demo cuando exista).

| Tiempo | Escena | Narración |
|---|---|---|
| 0:00–0:20 | Árbol de carpetas `Modules/Catalog`, `Modules/Orders`, `Modules/Payments` en el editor. | "This Laravel app has three modules. Nice folders. But folders don't stop anyone from importing another module's models." |
| 0:20–0:40 | Abrir `Modules/Orders/app/Models/Order.php`, resaltar `belongsTo(Product::class)`. | "Here, Orders reaches straight into Catalog's Eloquent model. Six months from now, renaming a column in Catalog breaks Orders, and nobody knows why." |
| 0:40–1:00 | `composer require --dev chrisabner/cordon-modulith` y `php artisan cordon:modules`. | "Cordon Modulith is a dev dependency. It detects your module layout, here nwidart, with no configuration." |
| 1:00–1:30 | `php artisan cordon:verify`. Pausa en las dos violaciones. | "It parses the code statically and finds two problems: Orders uses an internal class of Catalog, and Orders and Payments depend on each other in a cycle." |
| 1:30–2:00 | Pull request en GitHub con la anotación en la línea. | "In CI, the reusable GitHub Action turns each violation into an annotation on the pull request." |
| 2:00–2:30 | Editor: sustituir el modelo por `ProductCatalog` + `ProductData`; volver a ejecutar, queda solo el ciclo. Mostrar el cambio a evento en `Payments`. | "The fix is to depend on Catalog's public API: a contract and a data object. And to break the cycle, Payments publishes an event instead of calling Orders." |
| 2:30–2:50 | En un proyecto grande: `cordon:verify --generate-baseline`, luego `cordon:verify` en verde. | "Big legacy app? Record today's violations in a baseline. The build only fails on new ones, and you burn the baseline down module by module." |
| 2:50–3:00 | Página de docs y `php artisan cordon:docs` con el diagrama Mermaid renderizado. | "There's a Pest expectation, a PHPStan rule, a Boost skill for AI agents and living documentation. Cordon off your modules." |

## Notas de producción

- Sin música con letra; subtítulos en inglés y español.
- GIF corto (0:40–1:30) para el hilo de X y el README.
- No mostrar el logo de Laravel ni sugerir afiliación.
