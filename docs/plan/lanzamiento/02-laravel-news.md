# Envío a Laravel News (borrador)

Enviar por el formulario de paquetes de Laravel News. Texto en inglés, en tercera persona, sin superlativos.

---

**Package name:** Cordon Modulith

**Composer:** `chrisabner/cordon-modulith`

**Short description (one line):** Verify the boundaries between the modules of a Laravel application in CI.

**Description:**

Cordon Modulith is a development package for Laravel applications organised in modules, whether they use nwidart/laravel-modules, InterNACHI/modular or a plain `app/Modules` folder. It reads the code statically, builds the dependency graph between modules and reports when a module uses another module's internal classes, depends on a module it did not declare, or takes part in a dependency cycle.

Each module has a public API: by default, classes under `Contracts`, `Events`, `Data`, `Enums` and `Exceptions`, plus classes marked with `#[PublicApi]`. Everything else is internal.

```bash
composer require --dev chrisabner/cordon-modulith
php artisan cordon:verify
```

Existing projects can record current violations in a baseline and only fail on new ones. The package also includes:

- a reusable GitHub Action with pull request annotations and a `--module` option for per-module jobs;
- a Pest expectation: `expect('Billing')->toRespectBoundaries()`;
- a PHPStan rule that shows internal access in the editor;
- `php artisan cordon:docs`, which generates Mermaid diagrams, a page per module and an event inventory;
- a Laravel Boost guideline and skill for AI coding agents.

It is inspired by Spring Modulith and Shopify's Packwerk. Requires PHP 8.3+ and Laravel 12 or 13. MIT licensed.

**Links:** GitHub repository, documentation site.
