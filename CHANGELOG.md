# Changelog

All notable changes to Cordon Modulith are documented here. The project follows [Semantic Versioning](https://semver.org) from 1.0.0; before that, minor versions may contain breaking changes.

## [Unreleased]

## [0.4.0] - Unreleased

This release settles the model that 1.0 will freeze: rule severities, listener edges, a strict config and a versioned JSON report. See [UPGRADE.md](UPGRADE.md).

### May report different violations
- A listener's reference to another module's event no longer closes a dependency cycle. Two modules that call each other through a contract one way and an event the other are not reported as a cycle any more, and their `cycles` entries in the baseline become stale. Set `listeners.cycles` to `true` to count listeners again.
- Files outside the project root, such as the packages of a monorepo, are written relative to it with `../` (they used to be absolute paths, different on every machine). Baseline entries for those files become stale: run `cordon:verify --generate-baseline` once.
- `config/cordon.php` is validated before anything is analysed: unknown keys, unknown modules or dependencies, settings of the wrong type and unknown rule ids now stop `cordon:verify` with exit code `2`, with a suggestion for the closest valid name. They used to be ignored or only warned about, so a typo such as `depend_on` could leave a module open with CI green.

### Added
- Severities per rule: `error`, `warn` or `off` (`true` and `false` keep working). Violations of a rule in `warn` are reported, never fail, and are not written to the baseline. The JSON report lists them under `warnings`, the GitHub format as `::warning`. Custom rules take a severity when their class is the key.
- `listeners` config (`cycles: false`, `depends_on: true` by default), and listener references in the analysis: the type of the first parameter of `handle()` or `__invoke()` under an `Events` namespace (and that event elsewhere in the listener, such as `failed()`), the keys of an `EventServiceProvider`'s `$listen` map, and the event of `Event::listen()`, with a class name or a closure.
- `--fail-on-stale`: a baseline entry that no longer matches the code fails `cordon:verify`, and each one is listed with the occurrences it records and the ones found. The check is skipped when a file does not parse.
- The JSON report starts with `"version": 1` and is described by `resources/schemas/report-v1.schema.json`. Each violation has a `severity`.
- A per-module `metadata` key (free-form, not read yet) and the reserved `Module::Interface` syntax in `depends_on`, for named interfaces later.
- Every class in `src/` is marked `@api` or `@internal`; see [compatibility](https://chrisabner.github.io/Cordon-Modulith/guide/compatibility).

- PHPStan reports all three rules: `undeclared_dependency` (`cordon.undeclaredDependency`) per file, and `cycles` (`cordon.cycle`) with collectors on a full analysis, at the first reference of each dependency. `cordon.incompleteGraph` says when some modules were not analysed. Each error links to the rule's documentation.
- `cordon:verify <paths>`: report only the violations of some files or directories, plus the cycles their modules take part in, for pre-commit hooks (see the new recipe).
- `cordon:explain <class>` (its module, whether it is public and why, who uses it), `cordon:why <from> <to>` (the references behind a dependency, and whether `depends_on` allows it) and `cordon:impact <module>` (the modules that depend on it, directly or not). Also `explain`, `why` and `impact` in `vendor/bin/cordon`.
- `cordon:docs` groups each module's components by Laravel role (models, controllers, jobs, listeners, events...) and shows the module's `metadata` description and owner. `ClassDeclaration` has the parent class and interfaces; `Module` has the metadata.
- An `internal_access` on the model of an Eloquent relation (`$this->belongsTo(Model::class)` and its siblings) links to the recipe for cross-module relations. `Reference` has a new `relation` flag.
- `cordon:graph`: the module graph as Mermaid, DOT (Graphviz) or JSON, with the dependencies that have violations marked; `--module` keeps one module and its neighbours.
- `--format=gitlab`, `junit` and `checkstyle` reports, for GitLab Code Quality and the CI tools that read JUnit or Checkstyle.
- `unused_dependency` (a warning by default): a `depends_on` entry the module never uses. It says nothing about a module with a file that does not parse.
- `strict` per module: the baseline never suppresses a strict module's violations (nor the cycles it takes part in), and `--generate-baseline` leaves them out. The PHPStan rules follow it.
- `layers`: layers of modules from the top down, as a shorthand for `depends_on`; a module that depends on a layer above its own is a violation (`layers` rule). It checks nothing until `layers` is configured.
- `// @cordon-ignore <rule>[, <rule>] (<reason>)` accepts a violation where it is, as a decision rather than baseline debt. The reason is required; a comment without one, or with an unknown rule, exits with code `2`; a comment that no longer matches is a warning. The JSON summary counts them in `ignored`, and the PHPStan rules honour them.
- `AnalysisContext` has the files that could not be parsed (`parseErrors`), for custom rules.
- `host_access` (a warning by default): the code outside the modules (`host.paths`: `app`, `routes` and `database`, without the module directories) may only use the public API of the modules. Filament resources, controllers and routes that reach into a module's internals are reported (#25). `AnalysisContext::$hostEdges` has its references.
- A `parser-floor` CI job checks that the lowest `nikic/php-parser` allowed analyses modern PHP.

### Changed
- If you use the PHPStan extension, PHPStan now also reports `cordon.undeclaredDependency` and `cordon.cycle`, and `cordon.incompleteGraph` when its `paths` leave a module out. Add the module directories to `paths`, or ignore `cordon.incompleteGraph` if you analyse parts of the project on purpose (see the PHPStan guide).
- `nikic/php-parser` 5.3 or later is required. Older 5.x versions skipped files with PHP 8.4 property hooks as parse errors (5.0, 5.1) or lost the types of hook parameters (5.2).
- The PHPStan rule reports only while `internal_access` is set to `error`.
- The generated docs and the outdated baseline warning no longer say `php artisan` (#23).

### Fixed
- `cordon:verify` resolved the modules twice and read the baseline once per use (#20).
- The PHPStan rule reported nothing for the file being edited in editor mode (`--tmp-file`): its temporary path matched no module.

## [0.3.1] - 2026-11-05

A patch release: class names are matched regardless of case, and `cordon:modules` tells you when an app is split by layer rather than by feature.

### Added
- `cordon:modules` warns when the detected modules look like the layers of a standard Laravel app (`Http`, `Models`, `Providers`...) rather than modules split by feature, and the installation guide explains which projects Cordon fits.

### Fixed
- Class references are matched to modules and to public namespaces regardless of case, like PHP resolves class names. A reference written as `modules\catalog\Models\Product` was not attributed to `Catalog`, so its use of an internal class was not reported. Thanks to @bfs2021 (#26).

## [0.3.0] - 2026-11-05

This release makes Cordon easier to run on existing projects: CI no longer boots the application, PHP 8.2 is supported, the baseline can shrink safely, and the PHPStan rule applies it.

**Upgrading from 0.2:**
- The GitHub Action now runs `vendor/bin/cordon verify`, which does not boot the application. If your `config/cordon.php` needs the booted app, or a custom rule needs services the app registers, set `artisan: true`.
- The PHPStan rule now applies `cordon-baseline.json`. If you copied Cordon's violations into PHPStan's baseline, remove the `cordon.internalAccess` entries from it.
- To shrink the baseline, use `cordon:verify --prune-baseline` instead of regenerating it.

### Changed
- PHP 8.2 is now supported, with Laravel 12. Nothing in Cordon needed 8.3; only the test tooling did. CI now runs the suite on PHP 8.2, 8.3, 8.4 and 8.5 (Laravel 13 on 8.3 and later).
- `vlucas/phpdotenv` is now a dependency. Without it, `env()` failed outside a Laravel app, so the package defaults in `config/cordon.php` were silently dropped there. Laravel apps already have it.

### Fixed
- With PHPStan 2.3 or later, changing `cordon-baseline.json`, `config/cordon.php`, `config/modules.php` or `config/app-modules.php` now invalidates PHPStan's result cache. It used to keep showing the old `internal_access` errors until the analysed files changed.

### Added
- `cordon:verify` reports baseline entries that no longer match the code, and `--prune-baseline` removes them without adding new violations. Regenerating the baseline to shrink it also accepted any violation that had slipped in. The Boost skill now tells agents to prune instead.
- The PHPStan rule applies `cordon-baseline.json`, so the editor shows the same `internal_access` findings as `cordon:verify` and teams no longer keep a second baseline in PHPStan. It also reads the AST PHPStan already parsed instead of parsing each file again.
- `vendor/bin/cordon verify|modules|docs` runs the same commands as artisan without booting the application, so CI needs no `.env`, database or `APP_KEY`. The GitHub Action uses it by default; set `artisan: true` to keep `php artisan cordon:verify`.
- Case studies in the documentation: Cordon run on Bagisto (namespaces), Advising App (InterNACHI/modular) and LaravelEcomm (nwidart/laravel-modules), with the configuration used for each and what remained after declaring their public API.

## [0.2.0] - 2026-11-04

This release fixes three cases where `cordon:verify` passed when it should not. Two of them change behaviour.

**Upgrading from 0.1:**
- If `cordon-baseline.json` has `cycles` entries, regenerate it once with `php artisan cordon:verify --generate-baseline`. Cycles are now recorded per dependency, so the old entries no longer match and `cordon:verify` warns about them.
- A project with no modules yet now fails `cordon:verify`. Pass `--allow-empty` until the first module exists.

### Added
- `--format=github` reports each file that does not parse as a warning annotation, and the summary counts them. Their references are not checked, and the GitHub output used to leave them out. `--fail-on-parse-error` fails the run when there are any.

### Changed
- **Breaking:** `cordon:verify` now exits with `1` when no modules are found, instead of passing without analysing anything. A misconfigured resolver or a moved `app/Modules` used to keep CI green. With `--format=github` the reason is printed as an error annotation. Pass `--allow-empty` while a project has no modules yet.
- **Breaking:** `cycles` now reports one violation per dependency inside a cycle (target `Orders -> Payments`), with the shortest cycle that dependency closes, instead of one violation per group of modules (ADR 0011). With the old report, a module joining a cycle recorded in the baseline, or a new dependency inside it, stayed suppressed. The baseline format is now version 2: `cordon:verify` warns when a version 1 baseline has `cycles` entries, which no longer match. Regenerate the baseline once after upgrading.
- When modules are tangled in several cycles, the `cycles` message says so and names the whole group, instead of reading as a single cycle. In real apps the group often spans 25 to 30 modules while the example cycle has two.

## [0.1.1] - 2026-09-29

### Changed
- Global `public_namespaces` entries (`Contracts`, `Events`, `Data`, `Enums`, `Exceptions`) now match at any depth of a module, so `Billing\Invoices\Enums\Status` is public API (ADR 0010). Field tests showed these nested classes were about 20% of the reports in some apps. `#[Internal]` still keeps a class internal, and the per-module `public` list stays anchored at the module root.

### Added
- `SECURITY.md` with private vulnerability reporting, a code of conduct, and issue forms (bug, false positive, feature request).

## [0.1.0] - 2026-09-29

### Added
- `cordon:verify` command with `text`, `json` and `github` output formats.
- `cordon:modules` command to inspect the detected modules.
- Module resolvers for plain namespaces, nwidart/laravel-modules and InterNACHI/modular, with auto-detection.
- Static dependency extraction with nikic/php-parser (the analysed code is never loaded).
- Rules: `internal_access`, `undeclared_dependency`, `cycles`.
- Public API model: public namespaces, `#[PublicApi]`, `#[Internal]`, per-module `public` list and `open` modules.
- Baseline file for gradual adoption (`--generate-baseline`, `--no-baseline`).
- Laravel Boost guideline for AI coding agents.
- `--module` option for `cordon:verify` to report the violations of one or more modules.
- Pest expectation `toRespectBoundaries()` and `Cordon\Testing\Cordon::modules()`.
- PHPStan rule `cordon.internalAccess` (`extension.neon`), reading `config/cordon.php` without booting Laravel.
- Reusable GitHub Action (`action.yml`).
- `cordon:docs` command: Mermaid dependency diagrams, a canvas per module and an event inventory.
- `cordon:verify` and `cordon:docs` warn on stderr when Xdebug is active, since it makes the analysis several times slower.
- Custom rules: classes implementing `Cordon\Contracts\Rule` in the `rules` config.
- Laravel Boost skill `cordon-fix-violations`.
- Documentation site (`docs-site/`), benchmark (`composer bench`) and real-project fixtures.

### Fixed
- Commands read the config through the repository contract (PHPStan level 8).
