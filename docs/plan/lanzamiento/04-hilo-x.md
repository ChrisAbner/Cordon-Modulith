# Hilo de X (borrador, 6 posts)

Adjuntar el GIF de la terminal (escena 0:40–1:30 del guion de vídeo) en el post 3.

1. Your Laravel app has `Modules/Billing` and `Modules/Catalog`. Nice folders.
   Nothing stops Billing from `use Modules\Catalog\Models\Product;` though. 🧵

2. After a few months every module uses every other module's models, and the "modular monolith" is a monolith with extra folders.

3. Cordon Modulith checks module boundaries in CI:
   - internal classes used across modules
   - undeclared dependencies
   - dependency cycles
   `php artisan cordon:verify` [GIF]

4. Each module has a public API: Contracts, Events, Data, Enums, Exceptions, or `#[PublicApi]`. Everything else is internal. Works with nwidart, InterNACHI or plain `app/Modules`.

5. Existing app with hundreds of violations? Generate a baseline: CI only fails on new ones. Then burn it down module by module.

6. Also: `expect('Billing')->toRespectBoundaries()` in Pest, a PHPStan rule for your editor, Mermaid diagrams with `cordon:docs`, and a Boost skill so AI agents respect boundaries. Early days, feedback welcome: https://github.com/ChrisAbner/Cordon-Modulith
