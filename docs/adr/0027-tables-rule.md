# ADR 0027: The `tables` rule

**Status:** accepted (2026-11-25)

> The maintainer accepted the rule as it ships: `off` by default, meant to run as `warn`, with the three messages below. Its data (`AnalysisContext::$tables`, `TableMap`) stays `@internal`, like `$externalReferences` of ADR 0026, until it is confirmed against the compatibility page before 1.0.

## Context
Modules that share a database often couple through tables: one module alters a table another created, queries it with `DB::table`, or points a foreign key at it. That coupling is as real as a class reference and invisible to the other rules.

## Decision
- **Rule.** `tables` is `off` by default and meant to run as `warn`. It reports a module that alters (`Schema::table`, `drop`, `dropIfExists`, `rename`), queries (`DB::table` and the joins that follow it, or a model of the module whose table is another module's) or points a foreign key at (`foreign()->references()->on()`, `constrained()`) a table that another module creates. Three messages: `Module [Billing] alters table [orders], which belongs to [Orders].`, `... adds a foreign key to table ...` and `... queries table ...`, each followed by a hint that points to the cross-module relations recipe. One report per file, table and kind of use, and the `target` is the table. A foreign key is reported even when `depends_on` lists the owner.
- **Literal only.** Only literal names are read. A name in a variable, a constant or `config()` is not guessed. A join counts only on a chain that starts at `DB::table('...')` and stays on the query builder: a terminal call such as `get()` or `pluck()`, or a method not in the builder list, ends it. `foreignId('order_id')->constrained()` with no table is reported against `orders`, which Laravel's own `Str::plural` infers (`category_id` is `categories`, `person_id` is `people`). `foreignIdFor(Order::class)->constrained()`, `constrained($table)` and `constrained(column: 'uuid')` are not guessed.
- **Ownership.** The owner of a table is the module that creates it with `Schema::create`, wherever the migration lives. A migration in the code outside the modules (`database/migrations`, `host`) makes the host the owner, and the host's tables (`users` almost always) are never reported. A table that two modules create, or that nobody does, has no owner and is never reported. `cordon:docs` lists the tables without owner in the module cards, with the modules that create them and the reason. The code outside the modules is never reported either.
- **Models.** A model that sets `protected $table = '...'` uses that table. One that sets none uses Laravel's `Str::snake(Str::pluralStudly($class))`, but only when it provably extends `Illuminate\Database\Eloquent\Model` through the analysed code. When anything in the model can change its table in a way Cordon cannot follow (a `$table` or `getTable()` in a parent or trait of the project, `setTable()`, `$connection`), the table is unknown and the model is listed as without owner. Traits from packages (`HasFactory`, `SoftDeletes`) are assumed not to set `$table`. A model of a package, or a `Pivot`, is left out.
- **Nesting.** A nested module may use the tables of its ancestors, like the classes (ADR 0021).
- **Extractor only when on.** The extractor reads the tables of a file only while the rule is on (`PhpParserExtractor::$tables`), and turning the rule on or off is part of the cache fingerprint (ADR 0024). A project that leaves it off gets an unchanged extractor, analysis and output.
- **Data.** `FileAnalysis::$tables` and `AnalysisContext::$tables` (a `TableMap`) hold the data; empty unless the rule is on.

## Consequences
- A project that leaves `tables` off is unchanged, byte for byte.
- The rule is a heuristic and can be wrong in ways the others cannot, hence `off`, `warn` and the zero-false-positive choices above (when unsure, say nothing). Dynamic names are the main blind spot.
- `AnalysisContext::$tables` and `TableMap` are readable by custom rules but `@internal`, and not part of the 1.0 surface until they are confirmed against the compatibility page.
- Turning the rule on starts a new cache.
