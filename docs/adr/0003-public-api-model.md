# ADR 0003: Public API model

**Status:** accepted

## Context
To report internal access, Cordon Modulith must know which classes form a module's public API, with low configuration and escape hatches.

## Decision
Precedence: `#[Internal]` > `#[PublicApi]` > `open` module > public namespaces (global `public_namespaces` plus the module's `public` list) > internal. Default public namespaces: `Contracts`, `Events`, `Data`, `Enums`, `Exceptions`.

## Consequences
- Convention first: most projects need no attributes.
- Internal by default is strict; the baseline makes adoption possible.
