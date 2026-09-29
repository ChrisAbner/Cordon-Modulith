# ADR 0001: Static analysis with nikic/php-parser

**Status:** accepted

## Context
Boundary checks must run in CI on any project, including code that does not boot, and must not execute user code. Pest's `arch()` expectations rely on reflection and only see classes that exist and autoload.

## Decision
Parse files with nikic/php-parser v5 and `NameResolver`. A dependency is any fully qualified class name in a class position after name resolution. Function and constant names are excluded. `use` imports alone are not dependencies.

## Consequences
- Works without booting the application; fast and deterministic.
- Docblock-only types, string class names and dynamic references are not detected (documented limitation, zero false positives preferred).
