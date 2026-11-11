# ADR 0028: Text exports and the `Architecture` class

**Status:** proposed (the new `@api` class still awaits the maintainer's confirmation)

> The plan for 0.5 (approved on 2026-11-08, everything seen goes in) includes both, but a new `@api` class is a promise for 1.0, and the maintainer has not confirmed it. Accept this ADR, or keep `Architecture` internal until 1.0.

## Context
Tools that show the architecture inside an application (an admin panel such as the Filament plugin, a dashboard) need the module graph and the module cards as text, without running a command or writing files. Diagram tools beyond Mermaid and DOT, such as C4-PlantUML, are what architecture documentation uses.

## Decision
- **C4 export.** `cordon:graph --format=c4` prints a C4-PlantUML diagram (`!include <C4/C4_Component>`): a `Container_Boundary` for the application, a `Component` per module with the `description` of its `metadata`, a `Rel` per dependency labelled with its number of references (and the named interface it is limited to), and a nested module inside a `Boundary` of its parent. A dependency with violations is drawn red through a `violation` relationship tag (`AddRelTag`). Names and descriptions are escaped: quotes and percent signs become `%chr()` calls, a line break becomes `\n`, and a backslash is doubled and followed by a zero-width space (`%chr(8203)`), since PlantUML would draw the `\t` of `App\tenant` as a tab.
- **`cordon:docs --diagrams=c4`** also writes it as `architecture.puml`. `--diagrams=mermaid` is the default and changes nothing. An unknown value exits with `2`.
- **Which violations each uses.** Every export draws a dependency red when the violations it is given include one between those two modules. `cordon:graph` (any format, and `Architecture::graph()`) is given the violations that remain after the baseline. `cordon:docs`, `--diagrams=c4` included, documents the modules only (the code outside them is not analysed) and is given every violation in the code, whatever the baseline says. The Mermaid diagrams inside the module cards of `cordon:docs` are a different drawing: their labels count the classes used, as the tables next to them list, and only `internal_access` makes an arrow red.
- **`Cordon\Laravel\Architecture`** (`@api`): `Architecture::graph(?string $module = null)` returns the Mermaid flowchart of `cordon:graph` without the code fence, limited to a module and its neighbours with `$module` (an unknown module throws `InvalidArgumentException`), and `Architecture::docs()` returns the files of `cordon:docs` by name (`README.md`, `modules/<Module>.md`, `events.md`), exactly as the command writes them and with nothing written to disk. `graph()` applies the baseline as `cordon:graph` does. Both need a booted Laravel application (`LogicException` otherwise), and the caller checks `Cordon::configurationErrors()` first.
- **Cache.** `docs()` and `cordon:docs` read the files through the same extractor as `cordon:verify`, so with `cache` set an unchanged project is not parsed again. The output is byte-identical with and without the cache (ADR 0024).

## Consequences
- The exports are new options and a new class. Existing output does not change.
- `Architecture` joins the `@api` classes of the compatibility page. Changing its signatures after 1.0 needs a major release.
- The Markdown and diagram texts are not covered by the compatibility page, so a tool should show them, not parse them.
- The Filament plugin that uses `Architecture` is a separate package and is not published yet.
