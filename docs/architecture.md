# Architecture

## Goals

- Make module boundaries in Laravel applications explicit and verifiable in CI.
- Work on top of any module layout (nwidart, InterNACHI, plain namespaces) instead of replacing it.
- Never load or execute the analysed code.
- Zero false positives: every report must be a real boundary crossing.

## Pipeline

```mermaid
flowchart LR
    R[ModuleResolver] -->|ModuleMap| C[FileCollector]
    C -->|PHP files| E[DependencyExtractor]
    E -->|FileAnalysis: declarations + references| A[Analyzer]
    A -->|AnalysisContext: edges, symbols, policy| Ru[Rules]
    Ru -->|Violations| B[Baseline]
    B -->|Result| Rep[Reporter: text / json / github]
```

1. **ModuleResolver** discovers modules: name, namespace, path. Per-module config (`depends_on`, `public`, `open`) is applied with `ModuleMap::configure()`.
2. **FileCollector** lists `*.php` files in each module, skipping excluded directory names and Blade views.
3. **DependencyExtractor** (`PhpParserExtractor`) parses each file with nikic/php-parser, runs `NameResolver`, and collects:
   - declarations (class, interface, trait, enum) with `#[PublicApi]` / `#[Internal]`;
   - references: every `Name\FullyQualified` node after name resolution, except function and constant names. Imports alone are not references.
4. **Analyzer** maps each reference to a target module (longest namespace prefix) and each file to a source module (longest path prefix). References that cross modules become **edges**.
5. **Rules** receive an immutable `AnalysisContext` and yield `Violation`s.
6. **Baseline** removes known violations (keyed by rule + relative file + target, without line numbers).
7. **Reporter** renders the `Result`.

## Contracts (frozen)

| Contract | Responsibility |
|---|---|
| `Contracts\ModuleResolver::resolve(): ModuleMap` | Discover modules |
| `Contracts\DependencyExtractor::extract(string $file): FileAnalysis` | Static extraction, never loads code |
| `Contracts\Rule::id(): string`, `check(AnalysisContext): iterable<Violation>` | One boundary rule |
| `Contracts\Reporter::render(Result): string`, `formatted(): bool` | Output format |

Value objects: `Module`, `ModuleMap`, `ClassDeclaration`, `Reference`, `FileAnalysis`, `Edge`, `AnalysisContext`, `Violation`, `Result`.

## Layers

- `src/Analysis`, `src/Module`, `src/Rules`, `src/Resolvers`, `src/Reporters`, `src/Baseline`, `src/Support`: framework-agnostic core. It may use Symfony Console's `OutputFormatter` for escaping, nothing from Laravel.
- `src/Laravel`: service provider, `ResolverFactory` (reads Laravel config), Artisan commands.

## Decisions

See `docs/adr/`.
