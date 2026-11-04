# ADR 0012: A console that does not boot Laravel

**Status:** accepted
**Date:** 2026-11-04

## Context
`cordon:verify` and the GitHub Action ran through `php artisan`, which boots the whole application. Real applications often touch the database, a cache or external services while booting, or need `.env` and `APP_KEY`, so a purely static check failed in CI for reasons unrelated to module boundaries. A fresh Laravel 12 app whose `AppServiceProvider` opens a database connection is enough: `php artisan cordon:verify` stops with `SQLiteDatabaseDoesNotExistException` when there is no database.

The PHPStan rule (ADR 0007) already reads the configuration without the framework through `StandaloneConfig`.

## Decision
- `bin/cordon`, declared under `bin` in `composer.json`, runs `verify`, `modules` and `docs`. Each one is the artisan command itself (same options, output and exit codes), registered under a short name with the artisan name (`cordon:verify`...) as an alias.
- `StandaloneApplication` builds them on a bare `ProjectContainer` with `StandaloneConfig` as the config repository. The services come from `CordonServiceProvider::bindServices()`, which the provider also uses, so both entry points share one set of bindings.
- The project root is the current working directory, like PHPStan.
- Config files that cannot be evaluated without the framework are reported on stderr, so `--format=json` keeps stdout valid.
- Hints in `verify` name the binary that ran (`vendor/bin/cordon modules` or `php artisan cordon:modules`).
- The GitHub Action uses `vendor/bin/cordon verify` by default. The `artisan` input keeps the previous behaviour.
- `vlucas/phpdotenv` becomes a dependency. `env()` needs it, and without it the package defaults in `config/cordon.php` (which call `env()`) failed to load outside a Laravel app. Laravel apps already install it.

## Consequences
- CI needs PHP and `composer install`, nothing else: no `.env`, database or `APP_KEY`.
- `.env` is not loaded, so `env()` in `config/cordon.php` only sees real environment variables. Configuration that needs the booted application, and custom rules that need services only the application registers, still require artisan.
- `illuminate/container` is now declared, since `StandaloneConfig` and `StandaloneApplication` use it directly. It was already installed through `illuminate/console`.
- The baseline warning and the header of generated documentation still mention artisan commands, since they come from the framework-agnostic core.
