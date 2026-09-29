# Instructions for AI coding agents

This file is for AI agents (and agent swarms) working on the Cordon Modulith codebase.

## Project in one paragraph

Cordon Modulith is a Laravel dev-dependency that verifies boundaries between modules. It statically parses PHP files (nikic/php-parser, never loading code), maps class references to modules, and runs rules (`internal_access`, `undeclared_dependency`, `cycles`). Entry point: `php artisan cordon:verify`. Pipeline: `ModuleResolver` → `FileCollector` → `DependencyExtractor` → `Analyzer` (cross-module edges) → `Rule`s → `Baseline` → `Reporter`.

## Hard rules

1. **Contracts are frozen.** Do not change interfaces in `src/Contracts`, value objects in `src/Analysis` and `src/Module`, the config shape, the baseline JSON format or command options without an accepted ADR in `docs/adr`. If you believe a change is needed, stop and write the ADR proposal instead.
2. **Tests first.** Write or update the acceptance test before the implementation. Every detection change needs a fixture in `tests/Fixtures`.
3. **Never load analysed code.** No `class_exists`, `require`, reflection or autoloading of user code in `src/`.
4. **Zero false positives.** When unsure whether something is a dependency, don't report it and document the limitation.
5. **Do not edit `tests/Fixtures` line layout** without updating the tests that assert line numbers.
6. **Stay in your lane.** Swarms only touch the files listed in their brief (`docs/plan/enjambres/`). Integration across swarms is done by the maintainer.
7. **Quality gate:** `composer check` (Pint, PHPStan, Pest) must pass before you open a pull request.

## Conventions

- PHP 8.3, `declare(strict_types=1)`, `final` classes, `readonly` value objects.
- No Laravel dependency outside `src/Laravel`; the analysis core must stay framework-agnostic.
- User-facing messages: one sentence stating the problem plus one stating the fix.
