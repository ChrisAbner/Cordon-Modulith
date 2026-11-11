# ADR 0016: Tell listener edges apart from uses

**Status:** accepted
**Date:** 2026-11-07

## Context
Every cross-module reference was an `Edge` of one kind. A listener that handles another module's event is a reference to that module's class, so it closed dependency cycles: `Billing` publishes `InvoicePaid`, `Catalog` listens to it and calls `Billing` through a contract, and the pair was reported as a cycle. Events exist to avoid that coupling, since the module that publishes an event does not depend on whoever listens to it. Spring Modulith has an open issue for the same false cycles. In the case studies the cycles that only a listener closed were a visible part of the count.

## Decision
- A reference has a kind: `use` (anything that is not a listener's event) or `listener`. The extractor marks as `listener`:
  - the type of the first parameter of `handle()` or `__invoke()` when it is under an `Events` namespace, and that event anywhere else in the listener (`failed()`, for instance);
  - the keys of the `$listen` map of an `EventServiceProvider`;
  - the event of `Event::listen()`, with a class name or a closure.
  Class names are read like PHP reads them, without regard to letter case. A job or command with `handle(Service $s)` is dependency injection, not a listener, and stays a `use`. `subscribe()` is not recognised.
- A new config key controls how listeners count, with the defaults of the decision:

  ```php
  'listeners' => ['cycles' => false, 'depends_on' => true],
  ```

  With `cycles` false, a listener edge does not close a cycle. With `depends_on` true, it counts as a dependency: it must be declared in `depends_on`, and it counts as use for `unused_dependency` and for `layers`.
- Only `cycles` and `depends_on` are valid keys under `listeners`, both booleans (ADR 0019).
- `Edge::isListener()` and the constants `Reference::USE` and `Reference::LISTENER` are the way for custom rules to tell the kinds apart.

## Consequences
- Modules that call each other through a contract one way and an event the other way are not a cycle any more. Their `cycles` baseline entries become stale: prune them with `--prune-baseline`. Set `listeners.cycles` to `true` to count listeners as before.
- Case-study figures that include such cycles change and have to be recalculated.
- The segment `Events` is fixed. A listener of an event outside an `Events` namespace still counts as a use.
- `cordon:graph` draws a dependency made only of listeners dotted, and `cordon:why` explains when `depends_on` does not count them.

## Covers
- Config: `listeners.cycles`, `listeners.depends_on`.
- Types: `Reference::$kind`, `Reference::USE`, `Reference::LISTENER`, `Edge::isListener()`.
