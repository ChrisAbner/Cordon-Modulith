# Changelog

All notable changes to Cordon Modulith are documented here. The project follows [Semantic Versioning](https://semver.org) from 1.0.0; before that, minor versions may contain breaking changes.

## [Unreleased]

The decisions behind this release are recorded in [ADR 0021](docs/adr/0021-nested-modules.md) (nested modules), [0022](docs/adr/0022-named-interfaces.md) (named interfaces), [0023](docs/adr/0023-docblock-references.md) (docblock references), [0024](docs/adr/0024-analysis-cache.md) (the analysis cache), [0025](docs/adr/0025-since-and-affected.md) (`--since` and `cordon:affected`), [0026](docs/adr/0026-external-packages.md) (external packages), [0027](docs/adr/0027-tables-rule.md) (the `tables` rule, proposed) and [0028](docs/adr/0028-text-exports-and-architecture.md) (text exports and `Architecture`, proposed).

### Added
- Modules nested inside a module, as in Spring Modulith 1.3: `nested => ['Invoices', 'Payments']` (or `true`) in the settings of a module turns those folders into the modules `Billing.Invoices` and `Billing.Payments`. Outside `Billing` they do not exist (only what `Billing` exposes is public), inside it their public API applies, a child lists its siblings in `depends_on`, and cycles, the baseline, the reports, `cordon:modules` and `cordon:graph` use the full names. A project without `nested` is unchanged. See the [configuration](https://chrisabner.github.io/Cordon-Modulith/guide/configuration#nested-modules) guide.
- Named interfaces, as in Spring Modulith: `interfaces => ['Pricing' => ['Contracts\\PriceList', 'Data\\Price*']]` in the settings of a module names part of its public API, and `depends_on => ['Catalog::Pricing']` limits a module to it. A public class of `Catalog` outside the interfaces is an `undeclared_dependency` (`Module [Billing] uses ..., which is not in the interfaces it declares on [Catalog] (Pricing).`), in `cordon:verify` and in PHPStan. `Catalog` and `Catalog::*` keep meaning the whole public API, and a project without `interfaces` is unchanged. `unused_dependency` checks each interface, `cordon:explain` lists the interfaces of a class, `cordon:why` says when a dependency is limited to some, and `cordon:graph` labels the arrow. An interface that lists a class that is not public fails `cordon:verify` with exit code `2`, and a pattern that matches no class is a warning. See the [configuration](https://chrisabner.github.io/Cordon-Modulith/guide/configuration#named-interfaces) guide.
- `cache` config key (`null` by default): a file where Cordon Modulith keeps the analysis of each PHP file, keyed by a hash of its content, so a later run only parses the files that changed. The report is the same cold, warm or without it. `cordon:verify --no-cache` skips it for one run, and a cache that cannot be read or written is reported as a warning and ignored. See the [configuration](https://chrisabner.github.io/Cordon-Modulith/guide/configuration#cache) and [CI](https://chrisabner.github.io/Cordon-Modulith/guide/ci#keeping-the-analysis-cache-between-runs) guides.
- `docblocks` config key (`false` by default): with `true`, the class names in docblock types count as dependencies, as references of a new kind (`docblock`) that every rule treats like any other. It reads `@param`, `@return`, `@var`, `@property`, `@method`, `@throws`, `@extends`, `@implements`, `@use`, `@mixin` and the bound of `@template`, including generics, unions, `array<K, V>`, `list<T>`, `class-string<T>` and callable signatures, and resolves names with the namespace and imports of the file. Template names, type aliases, PHP and PHPDoc pseudo-types (`array-key`, `positive-int`, `static`, `$this`, `class-string` without an argument...) and anything that does not parse are never references. It applies to `cordon:verify`, `vendor/bin/cordon`, the Pest expectation and the PHPStan rule (which also takes a `cordon.docblocks` parameter), and it is part of the analysis cache fingerprint. A project that leaves it off is unchanged. It adds `phpstan/phpdoc-parser` (`^2.0`) as a dependency. Closes [#24](https://github.com/ChrisAbner/Cordon-Modulith/issues/24). See the [configuration](https://chrisabner.github.io/Cordon-Modulith/guide/configuration#docblocks) guide.
- `cordon:verify --since=<ref>` and `cordon:affected --since=<ref>`: work from what changed in git since a reference (what was committed since the branch forked from it, plus what is staged, modified or untracked, deleted files included). `verify --since` is `verify <changed PHP files>`: the whole project is analysed and only the violations of those files, and the cycles their modules take part in, are reported. `affected` lists the changed modules and the ones that depend on them (the graph of `cordon:impact`, with a nested module's parent and a parent's nested modules included), and `--tests` prints the test folders of those modules, one per line, for Pest: the `tests` setting of each module (also `host.tests`), or else `tests/<Module>` and `<module>/tests`. A reference that does not exist, a folder that is not a git repository or a reference that starts with `-` exits with `2`. Also `affected` in `vendor/bin/cordon`. See the new [affected tests](https://chrisabner.github.io/Cordon-Modulith/recipes/affected-tests) recipe.
- `external_dependency` rule and the `packages` config: `packages => ['shared' => ['laravel/framework', 'illuminate/*']]` and, per module, `packages => ['stripe/stripe-php']` limit the Composer packages a module may use (names, or a prefix ending in `*`). A module that uses another package gets `Module [Billing] uses Stripe\StripeClient from [stripe/stripe-php], which is not in its packages.`, once per file and package. A module without `packages` is unchanged, a nested module without its own list takes its parent's, and the code outside the modules is not checked. It reads `vendor/composer/installed.json` as data (a missing file, when a module lists `packages`, is a configuration error, and a package that is not installed a warning), and a class belongs to a package only when that package provably provides it, so PHP's classes (polyfills included), the project's and those of packages that are not installed are never reported. It is an error like `layers`, and checks nothing until some module lists `packages`. `cordon:why Billing stripe/stripe-php` lists the uses of a package by a module, and `cordon:explain` knows the classes of packages. `AnalysisContext::$externalReferences` lists the references to classes no module owns and `Module::$packages` holds the setting. See the [rules](https://chrisabner.github.io/Cordon-Modulith/guide/rules#external-dependency) and [configuration](https://chrisabner.github.io/Cordon-Modulith/guide/configuration#packages) guides.
- `tables` rule (heuristic, `off` by default, meant to run as `warn`): a module that alters (`Schema::table`, `drop`, `rename`), queries (`DB::table`, its joins, a model whose table is another module's) or points a foreign key at (`foreign()->references()->on()`, `constrained()`, with the table Laravel infers when it is not named) a table that another module creates gets `Module [Billing] alters table [orders], which belongs to [Orders].` (and the two messages for foreign keys and queries), once per file, table and kind of use. The owner of a table is the module that creates it, or the code outside the modules when a migration in `database/migrations` does; a table that two create, or that nobody does, and a model with a dynamic `$table`, are never reported, and `cordon:docs` lists them in the module cards as without owner, with the reason. It reads literal names only, and a nested module may use the tables of its ancestors. The extractor reads the tables only while the rule is on, so a project that leaves it off is unchanged, and turning it on starts a new analysis cache. `AnalysisContext::$tables` and `FileAnalysis::$tables` hold the data. See the [rules](https://chrisabner.github.io/Cordon-Modulith/guide/rules#tables) guide and the [cross-module relations](https://chrisabner.github.io/Cordon-Modulith/recipes/cross-module-relations) recipe.
- `cordon:graph --format=c4` exports the module graph as a C4-PlantUML diagram (`!include <C4/C4_Component>`): a `Container_Boundary` for the application, a `Component` per module with the `description` of its `metadata`, a `Rel` per dependency with its number of references (and the named interface it is limited to), red, through a `violation` relationship tag (`AddRelTag`), when it has violations, and a nested module inside a `Boundary` of its parent. Names and descriptions are escaped for PlantUML. `cordon:docs --diagrams=c4` also writes it as `architecture.puml` (`--diagrams=mermaid` is the default and changes nothing). See the [explore](https://chrisabner.github.io/Cordon-Modulith/guide/explore#the-whole-graph) guide.
- `Cordon\Laravel\Architecture`, a new `@api` class for tools that show the architecture (a dashboard, the Filament plugin): `Architecture::graph(?string $module = null)` returns the Mermaid flowchart of `cordon:graph` and `Architecture::docs()` the Markdown files of `cordon:docs` by file name, both as text, with nothing written to disk. `docs()` reads the files through the same analysis cache as `cordon:verify`, so it does not parse the project again when `cache` is set. See [ADR 0028](docs/adr/0028-text-exports-and-architecture.md).
- `cordon:affected --format=json` starts with `"version": 1`, like the other JSON reports: a new optional field keeps the version, removing or renaming one does not.
- Layers and nested modules: a nested module that `layers` does not name takes the layer of its nearest ancestor that it names, as source and as target, so `Billing.Payments` in the layer of `Billing` can no longer depend on a layer above it unnoticed. The use between a parent and its children is still never reported.

### Changed
- `Cordon::assertBoundaries()` and the `toRespectBoundaries()` expectation fail on invalid named interfaces (an interface that lists a class that is not public), as `cordon:verify` does with exit code `2`. They used to pass.
- With `docblocks` on, the reference counts in `cordon:graph`, `cordon:why` and `cordon:impact` go up: a class named in a type and in the code is two references (one of kind `use` and one of kind `docblock`). Nothing changes while `docblocks` is `false`.
- The analysis cache fingerprint also holds the installed versions of `phpstan/phpdoc-parser` and `illuminate/support`, so updating either starts a new cache.

## [0.4.0] - 2026-11-11

This release settles the model that 1.0 will freeze: rule severities, listener edges, a strict config, a versioned JSON report and the code outside the modules. Read [UPGRADE.md](UPGRADE.md) first: a broken config now stops `cordon:verify`, and some reports change. The decisions are recorded in [ADR 0015](docs/adr/0015-rule-severities.md) (severities, warnings and the baseline), [0016](docs/adr/0016-listener-edges.md) (listener edges), [0017](docs/adr/0017-host-module.md) (the `host`), [0018](docs/adr/0018-json-report-version.md) (the JSON report), [0019](docs/adr/0019-strict-configuration.md) (the strict config) and [0020](docs/adr/0020-rules-options-and-commands.md) (the other additions).

### Breaking
- `config/cordon.php` is validated before anything is analysed: unknown keys, unknown modules or dependencies, settings of the wrong type (`'Shared' => null`, a rule set to `null` or `1`), unknown rule ids and two custom rules with the same id stop `cordon:verify` with exit code `2`, with a suggestion for the closest valid name. They used to be ignored or only warned about, so a typo such as `depend_on` could leave a module open with CI green. `toRespectBoundaries()` and `Cordon::assertBoundaries()` fail on them too ([ADR 0019](docs/adr/0019-strict-configuration.md)).
- A listener's reference to another module's event no longer closes a dependency cycle. Two modules that call each other through a contract one way and an event the other are not reported as a cycle any more, and their `cycles` entries in the baseline become stale. Set `listeners.cycles` to `true` to count listeners again ([ADR 0016](docs/adr/0016-listener-edges.md)).
- The code outside the modules (`host.paths`: `app`, `routes` and `database` by default) is analysed for `host_access` (#25). `summary.files` in JSON and the file count of the text report include its files, a file there that does not parse is listed as skipped (and turns off `--fail-on-stale`), and a module named `host` is a configuration error. Set `host.paths` to `[]` to keep the 0.3 counts ([ADR 0017](docs/adr/0017-host-module.md)).
- Files outside the project root, such as the packages of a monorepo, are written relative to it with `../` (they used to be absolute paths, different on every machine). Baseline entries for those files become stale: run `cordon:verify --generate-baseline` once.
- If you use the PHPStan extension, PHPStan now also reports `cordon.undeclaredDependency` and `cordon.cycle`, which can fail a run that passed, and `cordon.incompleteGraph` when its `paths` leave a module out. Add the module directories to `paths`, or ignore `cordon.incompleteGraph` if you analyse parts of the project on purpose (see the PHPStan guide).
- `nikic/php-parser` 5.3 or later is required. Older 5.x versions skipped files with PHP 8.4 property hooks as parse errors (5.0, 5.1) or lost the types of hook parameters (5.2).
- `ModuleMap::configurationWarnings()` is removed: use `Cordon\Support\ConfigValidator::errors()`, which reports the same problems as errors.

### Added
- Severities per rule: `error`, `warn` or `off` (`true` and `false` keep working). Violations of a rule in `warn` are reported, never fail, and are neither written to the baseline nor hidden by it. The JSON report lists them under `warnings`, the GitHub format as `::warning`. Custom rules take a severity when their class is the key ([ADR 0015](docs/adr/0015-rule-severities.md)).
- `listeners` config (`cycles: false`, `depends_on: true` by default), and listener references in the analysis: the type of the first parameter of `handle()` or `__invoke()` under an `Events` namespace (and that event elsewhere in the listener, such as `failed()`), the keys of an `EventServiceProvider`'s `$listen` map, and the event of `Event::listen()`, with a class name or a closure.
- `--fail-on-stale`: a baseline entry that no longer matches the code fails `cordon:verify`, and each one is listed with the occurrences it records and the ones found. The check is skipped when a file does not parse.
- The JSON report starts with `"version": 1`, is described by `resources/schemas/report-v1.schema.json` and gives each violation a `severity` and the `docs_url` of its rule. A new optional field keeps version 1; removing or renaming one does not ([ADR 0018](docs/adr/0018-json-report-version.md)).
- `--format=gitlab`, `junit` and `checkstyle` reports, for GitLab Code Quality and the CI tools that read JUnit or Checkstyle.
- `host_access` (a warning by default): the code outside the modules may only use the public API of the modules. Filament resources, controllers and routes that reach into a module's internals are reported (#25). `AnalysisContext::$hostEdges` has its references.
- `unused_dependency` (a warning by default): a `depends_on` entry the module never uses. It says nothing about a module with a file that does not parse.
- `public_api_leak` (a warning by default): a public class whose public methods, properties or constants name an internal class of its own module. `Reference` has a new `signature` field, and `AnalysisContext::$signatures` lists those references.
- `layers`: layers of modules from the top down, as a shorthand for `depends_on`; a module that depends on a layer above its own is a violation (`layers` rule), reported once per file and class (the message names it, [ADR 0014](docs/adr/0014-layer-violations-per-class.md)) so the baseline tracks classes. It checks nothing until `layers` is configured.
- `strict` per module: the baseline never suppresses a strict module's violations (nor the cycles it takes part in), and `--generate-baseline` leaves them out. The PHPStan rules follow it.
- `// @cordon-ignore <rule>[, <rule>] (<reason>)` accepts a violation where it is, as a decision rather than baseline debt. The reason is required; a comment without one, or with an unknown rule, exits with code `2`; a comment that no longer matches is a warning. The JSON summary counts them in `ignored`, and the PHPStan rules honour them.
- PHPStan reports all three of its rules: `undeclared_dependency` (`cordon.undeclaredDependency`) per file, and `cycles` (`cordon.cycle`) with collectors on a full analysis, at the first reference of each dependency. Each error links to the rule's documentation.
- `cordon:verify <paths>`: report only the violations of some files or directories, plus the cycles their modules take part in, for pre-commit hooks (see the new recipe).
- `cordon:explain <class>` (its module, whether it is public and why, who uses it), `cordon:why <from> <to>` (the references behind a dependency, and whether `depends_on` allows it) and `cordon:impact <module>` (the modules that depend on it, directly or not). Also `explain`, `why`, `impact` and `graph` in `vendor/bin/cordon`.
- `cordon:graph`: the module graph as Mermaid, DOT (Graphviz) or JSON, with the dependencies that have violations marked; `--module` keeps one module and its neighbours.
- `cordon:docs` groups each module's components by Laravel role (models, controllers, jobs, listeners, events...) and shows the module's `metadata` description and owner. `ClassDeclaration` has the parent class and interfaces; `Module` has the metadata.
- An `internal_access` on the model of an Eloquent relation (`$this->belongsTo(Model::class)` and its siblings) links to the recipe for cross-module relations. `Reference` has a new `relation` flag.
- `Cordon::assertBoundaries()` and `php artisan vendor:publish --tag=cordon-tests`: one Pest test that fails with every module crossing its boundaries, and on a broken config or no modules.
- A per-module `metadata` key (free-form) and the reserved `Module::Interface` syntax in `depends_on`, for named interfaces later.
- Every class in `src/` is marked `@api` or `@internal`; see [compatibility](https://chrisabner.github.io/Cordon-Modulith/guide/compatibility).
- `AnalysisContext` has the files that could not be parsed (`parseErrors`), for custom rules.
- A `parser-floor` CI job checks that the lowest `nikic/php-parser` allowed analyses modern PHP.

### Changed
- A violation of a rule set to `error` is always reported as an error, whatever severity a custom rule gave it, so the JSON report matches its schema. `RuleSet::build()` returns the rules with their severities; a rule the container decorates keeps the severity of its config entry.
- With no modules, `cordon:verify` still validates `config/cordon.php` (root keys, rules, listeners, host) and exits with code `2` on an error, before saying no modules were found.
- The PHPStan rules report only while their rule is set to `error`.
- `ModuleResolver`, `Reporter` and `DependencyExtractor` are `@internal`: Cordon only uses them with the drivers it ships.
- The generated docs and the outdated baseline warning no longer say `php artisan` (#23).

### Fixed
- `cordon:verify` resolved the modules twice and read the baseline once per use (#20).
- Every report, the baseline, `cordon:docs` and `cordon:graph` are the same byte for byte whatever order the files are read in: the rules see references sorted by file and line (the line a violation reports is its first reference), and the event inventory lists publishers and listeners in file order.
- Class names written in another letter case (`\app\modules\billing\events\invoicepaid`) are read like PHP reads them: a listener's event under an `events` namespace, `#[\cordon\attributes\publicapi]`, an `EventServiceProvider` and the `Event` facade are recognised whatever their case. They used to turn a listener into a plain use (and could report a cycle) or ignore the attribute.
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
