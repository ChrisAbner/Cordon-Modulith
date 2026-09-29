# ADR 0008: Living documentation (`cordon:docs`)

**Status:** accepted

## Context
Spring Modulith generates architecture documentation from the code. Brief 05 asks for the same in Laravel: dependency diagrams, a canvas per module and an event inventory.

## Decision
- New command `php artisan cordon:docs {--output=docs/architecture}` writes Markdown:
  - `README.md`: overview table and a Mermaid diagram of all module dependencies. Edge labels count the distinct classes used; edges that include internal access are red.
  - `modules/<Module>.md`: namespace, path, `depends_on`, `open`, a Mermaid diagram of the module and its direct neighbours, its public API, the classes it uses from other modules and the ones other modules use from it (internal ones are flagged), its events and its current violations.
  - `events.md`: every module event with the classes that publish and listen to it.
- Output is deterministic (sorted, no timestamps) so it can be committed and reviewed in pull requests.
- Violations are shown **without** the baseline: the documentation describes the real state of the code.
- Events are detected statically (`Cordon\Documentation\EventExtractor`): classes under a module's `Events` namespace, or used explicitly with `event(new X)`, `broadcast(new X)`, `->dispatch(new X)`, `Event::dispatch(new X)` or `Event::listen(X::class)`. `X::dispatch()` and `handle(X $x)` only count for classes that are already events, because jobs use the same methods.
- `Analyzer` gains `snapshot(ModuleMap): Snapshot` and `check(Snapshot): Result`; `analyze()` is now the composition of both, with the same behaviour. `Snapshot` is a new value object (context, file analyses, parse errors) so the generator can reuse the edges and declarations without changing `Result`.

## Consequences
- New public API: the command, its `--output` option and the file layout above.
- Event listeners registered only through event discovery on `handle()` of classes outside the `Events` convention are not linked; string event names and wildcard listeners are not detected.
