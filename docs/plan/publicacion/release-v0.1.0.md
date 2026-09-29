# GitHub Release v0.1.0

- **Tag:** `v0.1.0` (sobre `main`, después de mergear el PR).
- **Release title:** `v0.1.0`
- Marcar "Set as a pre-release": el README declara 0.1 alpha.

## Notas (pegar tal cual)

````markdown
First public release of **Cordon Modulith**: verifiable boundaries between the modules of a Laravel application, checked in CI. It statically parses your code (nothing is loaded or executed) and fails the build when a module uses another module's internal classes, depends on a module it did not declare, or takes part in a dependency cycle.

> **Status: 0.1 (alpha).** The public API may change before 1.0. Cordon Modulith is a community project and is not affiliated with or endorsed by Laravel.

## Install

Requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require --dev chrisabner/cordon-modulith
php artisan cordon:modules    # check the detected modules
php artisan cordon:verify     # verify the boundaries
```

Existing project with many violations? Record them and only fail on new ones:

```bash
php artisan cordon:verify --generate-baseline
```

Use it in CI with the reusable action:

```yaml
steps:
  - uses: actions/checkout@v4
  - uses: ChrisAbner/Cordon-Modulith@v0.1.0
```

## Highlights

- **Three rules:** `internal_access`, `undeclared_dependency` and `cycles`, plus your own rules through `Cordon\Contracts\Rule`.
- **Public API per module:** `Contracts`, `Events`, `Data`, `Enums` and `Exceptions` by default, `#[PublicApi]` and `#[Internal]` attributes, per-module `public` lists and `open` modules for shared kernels.
- **Works with your layout:** nwidart/laravel-modules, InterNACHI/modular or a plain `app/Modules` folder, auto-detected.
- **Adopt gradually:** baseline file (`--generate-baseline`, `--no-baseline`) and `--module` to report one module at a time.
- **Where you already work:** `text`, `json` and `github` (pull request annotations) output; Pest expectation `expect('Billing')->toRespectBoundaries()`; PHPStan rule `cordon.internalAccess`; reusable GitHub Action.
- **Living documentation:** `php artisan cordon:docs` generates Mermaid dependency diagrams, a canvas per module and an event inventory.
- **AI coding agents:** a Laravel Boost guideline and the `cordon-fix-violations` skill.
- **Fast:** about 1 ms per file, so 1,000 files in roughly a second (benchmark in CI with a 10 s budget).

## Known limitations

- Docblock-only types (`@var`, generics), string class names (`'App\\Foo'`, `app('...')`) and dynamic references are not detected, on purpose, to avoid false positives.
- Code outside modules (for example `app/Http`) is not analysed.
- Listening to another module's event counts as a dependency (for `depends_on` and cycles).
- Dependency cycles and `depends_on` are only checked by `cordon:verify`, not by the PHPStan rule.
- The PHPStan rule reads `config/cordon.php` without booting Laravel, so keep that file a plain array.
- Validated on fixtures modelled after real projects; feedback from real applications is very welcome.

## Links

- Documentation: https://chrisabner.github.io/Cordon-Modulith/
- Packagist: https://packagist.org/packages/chrisabner/cordon-modulith
- Changelog: https://github.com/ChrisAbner/Cordon-Modulith/blob/main/CHANGELOG.md
- Issues and feedback: https://github.com/ChrisAbner/Cordon-Modulith/issues
````
