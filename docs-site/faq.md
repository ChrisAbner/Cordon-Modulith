# FAQ

## Does it slow down my application?

No. It is a dev dependency that only runs when you call `cordon:verify`, `cordon:docs`, your tests or PHPStan. Nothing runs at request time.

## How fast is it?

Around 1 ms per file on a typical CI runner (Linux, no Xdebug): about a second for 1,000 files, five for 5,000. The analysis is linear in the number of files.

Run it with Xdebug off (`XDEBUG_MODE=off php artisan cordon:verify`): Xdebug makes it 3-4x slower. On Windows the first run can be slower because of cold file reads and antivirus scanning; measured there with Xdebug off, 1,000 synthetic files took 5-7 s and a real 584-file project about 3 s once warm.

## Does it load or execute my code?

No. Files are parsed with nikic/php-parser; nothing is required, autoloaded or reflected. It works on code that doesn't boot and on classes that don't exist yet. Only [custom rules](./guide/custom-rules) are loaded, because they are extension code you wrote.

## Why is a class I only `use` not reported?

An import alone is not a dependency. As soon as the class is used (type, `new`, static call, `::class`...), it counts.

## Why is `app(Product::class)` reported but `app('App\\Models\\Product')` is not?

`Product::class` is a class reference. A string could be anything, so it is not reported: false positives are treated as bugs. The same applies to docblock types.

## What about code outside modules, like `app/Http`?

It is not analysed: Cordon Modulith checks the boundaries between modules. Controllers in `app/Http` may use any module; if they should not, move them into modules.

## What are the known limitations?

Docblock-only types (`@var`, generics), string class names (`'App\\Foo'`, `app('...')`) and dynamic references are not detected, and code outside modules is not analysed. When Cordon runs standalone (the PHPStan rule), project config files are evaluated without booting Laravel. If one can't be evaluated, the rule reports a `cordon.configuration` error and falls back to defaults; see [PHPStan](./guide/phpstan#configuration-errors).

## Are tests analysed?

Directories named `tests` or `Tests` are skipped by default (tests often reach into internals on purpose). Change `exclude` in the config if you want them checked.

## Does listening to another module's event count as a dependency?

Yes: your listener references the event class. Events are public API, so it is not an `internal_access`, but it counts for `depends_on` and cycles.

## Can I use it without Laravel?

The core (`src/Analysis`, `src/Rules`, `src/Resolvers`...) is framework-agnostic, and the PHPStan rule runs without booting Laravel, but the commands and the Pest expectation need a Laravel application.

## Is it affiliated with Laravel?

No. Cordon Modulith is a community project.
