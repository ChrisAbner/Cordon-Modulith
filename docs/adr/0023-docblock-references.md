# ADR 0023: Class references in docblocks

**Status:** accepted (2026-11-08, plan approved by the maintainer: everything seen goes in)

> The maintainer approved the plan for 0.5 on 2026-11-08 with the decision that everything seen goes in. This closes issue #24. The tags and edge cases below are the plan's.

## Context
Laravel code often writes its types in docblocks (`@var Collection<int, Invoice>`, `@param list<Order> $orders`). Cordon read only class names written in code, so a module could depend on another through generics and the dependency was invisible to every rule.

## Decision
- `docblocks` in the config, `false` by default. With `true`, class names in docblock types are references of a new kind, `Reference::DOCBLOCK` (`docblock`), that go through the same rules as the references of kind `use`: `internal_access`, `undeclared_dependency`, `unused_dependency`, `cycles`, `layers`, named interfaces and the baseline. The PHPStan rule reads the key from `config/cordon.php`, and the `cordon.docblocks` parameter takes precedence.
- Tags read, with generics, unions, intersections, `array<K, V>`, `list<T>`, `class-string<T>`, callable signatures and array shapes: `@param`, `@return`, `@var`, `@property` (and `-read`, `-write`), `@method`, `@throws`, `@extends`, `@implements`, `@use`, `@mixin`, the bound of `@template T of Foo`, and `@phpstan-type` / `@phpstan-import-type` with their `@psalm-` forms. Only `/** */` blocks are read, and names are resolved with the namespace and imports of the file.
- Zero false positives: template names and type aliases inside their scope, PHP and PHPDoc pseudo-types (`array-key`, `positive-int`, `static`, `$this`, `class-string` without an argument), array shape keys, free text and tags that are not listed (`@see`, `@param-out`, `@phpstan-assert`) are never references. A tag that does not parse is skipped with the rest of that tag, and a broken docblock never fails an analysis.
- A docblock reference is reported at the line of the first use of that class in code, so turning the setting on does not move an existing violation and a `@cordon-ignore` keeps working. A listener's event named in a docblock of the listener counts as the listener's reference.
- The parser is `phpstan/phpdoc-parser` ^2.0, a new dependency. The setting is part of the cache fingerprint (ADR 0024), together with the installed version of the parser.
- A class named in a type and in the code is two references, one of each kind, so the reference counts in `cordon:graph`, `cordon:why` and `cordon:impact` go up when the setting is on. `cordon:graph --format=json` lists `docblock` among the `kinds` of a dependency.

## Consequences
- A project that leaves `docblocks` off is unchanged, but `phpstan/phpdoc-parser` is installed.
- Turning it on can report violations that were there all along, in the files of the project that use docblock types.
- `Reference::$kind` has a new value. Custom rules that switch on the kind must handle it, as they must for `listener`.
- Docblock references have no column and a line of the first use, not of the docblock. Reports pointing to the docblock line are left for later.
