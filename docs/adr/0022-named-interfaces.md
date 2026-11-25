# ADR 0022: Named interfaces

**Status:** accepted (2026-11-08, plan approved by the maintainer: everything seen goes in)

> The maintainer approved the plan for 0.5 on 2026-11-08 with the decision that everything seen goes in. `Module::Interface` was reserved in `depends_on` by ADR 0020, and the names and details are the plan's.

## Context
A module's public API is all or nothing: a module that depends on `Catalog` may use every public class. Spring Modulith lets a module name subsets (named interfaces) and lets a dependent ask for one, so `Billing` can use the price list of `Catalog` without the stock API.

## Decision
- `interfaces` in the settings of a module: `'Pricing' => ['Contracts\\PriceList', 'Data\\Price*']`. Each entry is a class name relative to the module namespace. A trailing `*` makes it a prefix. Names are compared without regard to case. A class can be in several interfaces, or in none.
- `depends_on => ['Catalog::Pricing']` limits a module to that interface. `Catalog` and `Catalog::*` keep meaning the whole public API. With several entries for a module, the union applies. `Parent.Child::Interface` is allowed.
- A public class of `Catalog` outside the interfaces a module declares is an `undeclared_dependency`: `Module [Billing] uses ..., which is not in the interfaces it declares on [Catalog] (Pricing).` A listener that handles an event outside the interface is the same, unless `listeners.depends_on` is `false`. A class that is internal is reported by `internal_access` only.
- An interface does not make a class public. A listed class that is not public (a public namespace, `#[PublicApi]`, the module's `public` list or `open`) is an error: `cordon:verify` exits with `2` and the Pest expectation fails (`Cordon::assertBoundaries()` and `toRespectBoundaries()`). A pattern that matches no public class is a warning.
- `ConfigValidator` rejects an interface that the module does not define (`Catalog::Pricng`, with a suggestion) and an interface of a module that defines none.
- `unused_dependency` checks each entry: `Catalog::Pricing` is unused when no class of `Pricing` is used. `cordon:explain` lists the interfaces of a class, `cordon:why` says when a dependency is limited, and `cordon:graph` labels the arrow (`3 Catalog::Pricing`).
- With nested modules (ADR 0021) a nested module may use any class of its ancestors, so interfaces never restrict it from using its parent. `Billing::Api` can list classes of the children of `Billing`, which must be public outside `Billing`.
- The PHPStan rule applies the same check with the same message. The checks that need the classes run in `cordon:verify` and the Pest expectation.

## Consequences
- A project without `interfaces` is unchanged.
- `Module::$interfaces` is an optional field. The audit that finds invalid interfaces (`InterfaceAudit`) is internal.
- The expectation fails on an invalid interface where it passed before (the feature is new, so no existing project is affected).
- Interfaces are by class name and prefix, not by attribute. An attribute could come later without changing the config.
