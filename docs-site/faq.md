# FAQ

## Does it slow down my application?

No. It is a dev dependency that only runs when you call `cordon:verify`, `cordon:docs`, your tests or PHPStan. Nothing runs at request time.

## How fast is it?

About 1 ms per file on a laptop: 1,000 files in roughly one second, 5,000 in five. The analysis is linear in the number of files.

## Does it load or execute my code?

No. Files are parsed with nikic/php-parser; nothing is required, autoloaded or reflected. It works on code that doesn't boot and on classes that don't exist yet. Only [custom rules](./guide/custom-rules) are loaded, because they are extension code you wrote.

## Why is a class I only `use` not reported?

An import alone is not a dependency. As soon as the class is used (type, `new`, static call, `::class`...), it counts.

## Why is `app(Product::class)` reported but `app('App\\Models\\Product')` is not?

`Product::class` is a class reference. A string could be anything, so it is not reported: false positives are treated as bugs. The same applies to docblock types.

## What about code outside modules, like `app/Http`?

It is not analysed: Cordon Modulith checks the boundaries between modules. Controllers in `app/Http` may use any module; if they should not, move them into modules.

## Are tests analysed?

Directories named `tests` or `Tests` are skipped by default (tests often reach into internals on purpose). Change `exclude` in the config if you want them checked.

## Does listening to another module's event count as a dependency?

Yes: your listener references the event class. Events are public API, so it is not an `internal_access`, but it counts for `depends_on` and cycles.

## Can I use it without Laravel?

The core (`src/Analysis`, `src/Rules`, `src/Resolvers`...) is framework-agnostic, and the PHPStan rule runs without booting Laravel, but the commands and the Pest expectation need a Laravel application.

## Is it affiliated with Laravel?

No. Cordon Modulith is a community project.
