# ADR 0020: Other additions of 0.4: rules, module options, commands and the API surface

**Status:** accepted (2026-11-11)

> The maintainer decided on 2026-11-08 that 0.4 includes everything in the plans, but recorded no decision on the shape of these items: names, defaults and details are the plan's, as the code implements them. Accept this ADR, or split it and reject what should change before 1.0 freezes it.

## Context
Besides the decisions of ADRs 0015 to 0019, the plans for 0.4 add rules, module options, commands and a stated API surface. They touch the contracts of AGENTS.md rule 1: the config shape, command options, value objects. Most additions are optional parameters at the end of constructors, so they are compatible. They are listed here so each one has a record before 1.0.

## Decision
**Rules** (ids in `rules`, new violations in the JSON report and the baseline):
- `unused_dependency` (default `warn`): a `depends_on` entry that the module never uses. One violation per module and dependency, target `Module -> Dependency`, no file. It says nothing about a module with a file that does not parse.
- `public_api_leak` (default `warn`): a public class whose public methods, properties or constants name an internal class of its own module. One violation per file and internal class, at the first member that names it. Constructor parameters that are not promoted-public do not count. `Reference::$signature` and `AnalysisContext::$signatures` carry the references.
- `layers` (default `error`, inactive without the `layers` key): see ADR 0014 for how it reports.

**Config**
- `layers`: layers from the top down, each a list of module names. A module may depend on its own layer and the ones below. A layer cannot be empty, a module belongs to one layer, and modules left out are not checked. A shorthand for `depends_on`.
- Per module: `strict` (boolean) and `metadata` (free-form array, not read by the analysis; `cordon:docs` shows `description` and `owner`). With `strict`, the baseline never suppresses the violations the module causes, nor the cycle dependencies it takes part in, and `--generate-baseline` leaves them out and says how many. Entries recorded before a module became strict stay in the file, never applied.
- `Module::Interface` is reserved in `depends_on` for named interfaces and is an error today.

**In-place acceptance:** a comment `// @cordon-ignore <rule>[, <rule>] (<reason>)` accepts a violation where it stands. It covers its own line when it follows code, and the statement below it when it is on its own line. The reason is required. A directive without one, or with an unknown rule or `cycles` or `unused_dependency` (they report no line), exits with code `2`. A directive that no longer matches is a warning. The JSON summary counts them in `ignored`. The PHPStan rules honour them.

**Commands and options**
- `cordon:verify <paths>`: report only the violations of those files or directories, plus the cycles their modules take part in. The whole project is still analysed. Combined with `--generate-baseline` or `--prune-baseline` it exits with `2`.
- `cordon:verify --fail-on-stale` (ADR 0015) and `--format=gitlab|junit|checkstyle` (ADR 0018).
- `cordon:explain <class>`, `cordon:why <from> <to>` and `cordon:impact <module>`, each with `--format=text|json`; `cordon:graph` with `--format=mermaid|dot|json` and `--module`. All exist in `vendor/bin/cordon` as `explain`, `why`, `impact` and `graph`. An unknown format exits with `2` and errors go to stderr.
- `php artisan vendor:publish --tag=cordon-tests` writes a Pest test that calls `Cordon::assertBoundaries()`.
- PHPStan: new identifiers `cordon.undeclaredDependency` and `cordon.cycle` (cycles with collectors, on a full analysis only), and `cordon.incompleteGraph` when `paths` leave a module out. The `phpstan.rules.rule` registration in `extension.neon` changes from one service to a shared `CordonContext` plus the rules and a collector.

**API surface:** classes marked `@api` follow the compatibility page: `Contracts\Rule`, `Analysis\AnalysisContext`, `Violation`, `Edge`, `Reference`, `ClassDeclaration`, `SymbolTable`, `PublicApiPolicy`, `DeclaredVisibility`, `Module\Module`, `ModuleMap`, `Rules\Severity`, `Attributes\PublicApi`, `Attributes\Internal` and `Testing\Cordon`. Everything else is `@internal`, including the interfaces `ModuleResolver`, `Reporter` and `DependencyExtractor`, which only worked with the drivers Cordon ships.

**Paths outside the project root** are written relative to it, with `../` (ADR 0018). The baseline keys of those files change.

**Dependencies:** `nikic/php-parser` ^5.3 (property hooks of PHP 8.4 need it).

## Consequences
- New value-object fields are optional and last: `Reference::$kind`, `$relation` and `$signature`, `Violation::$severity`, `ClassDeclaration::$parent` and `$interfaces`, `Module::$metadata` and `$strict`, `AnalysisContext::$parseErrors`, `$hostEdges` and `$signatures`. Code that builds them keeps working.
- A custom rule that implements `Contracts\Rule` keeps working. A project that implemented `ModuleResolver` or `Reporter` itself has no supported way to plug them in, as in 0.3.
- Defaults that start in `warn` follow the compatibility page: new checks do not fail an upgrade.
- `public_api_leak` reports one class at a time, so a second leak of the same class shows only after the first is fixed.
- `strict` leaves dead entries in the baseline until the module's violations are fixed.
- Tests that follow the guide for custom rules build `Analyzer`, `RuleSet` and `PhpParserExtractor`, which are `@internal`. A public test helper is left for later.
