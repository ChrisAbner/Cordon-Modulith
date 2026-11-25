# ADR 0021: Nested modules

**Status:** accepted (2026-11-08, plan approved by the maintainer: everything seen goes in)

> The maintainer approved the plan for 0.5 on 2026-11-08 with the decision that everything seen goes in. The names, defaults and details below are the plan's, as the code implements them.

## Context
Spring Modulith 1.3 lets a module contain modules. Large Laravel modules (`Billing` with invoices and payments) want the same: the parent exposes one public API to the outside, and its parts keep boundaries between them. Until now a module was a flat folder, so either `Billing` was one module with no boundaries inside, or each part was a module that every other module could see.

## Decision
**Configuration.** `nested` in the settings of a module lists the folders directly under it that are modules (`['Invoices', 'Payments']`), or `true` for every folder that is not a public namespace. A nested module is named `Parent.Child`: the dot is the separator, so a folder whose name contains a dot is a configuration error (`nested => true` leaves it out). A nested module can have nested modules. Its settings go under the full name. Cordon resolves them in `NestedResolver`, on top of the resolver in use.

**Model.** `Module` has a `parent` (the name of the module it is nested in, null for a top-level one) and `isNestedIn()`. `ModuleMap` has `parentOf()`, `topLevel()`, `visible()`, `isInside()` and `hierarchy()`. They are optional additions: a project without `nested` has no module with a parent.

**Visibility.**
- Outside the parent a child does not exist. `ModuleMap::visible($target, $consumer)` returns the module a consumer sees when it uses a class of $target: the child itself from inside its parent, the parent from anywhere else. A class of a child is internal to the outside unless the parent's public list, `#[PublicApi]` or `open` makes it public.
- Inside the parent the public API of each child applies, between the parent and its children and between siblings.
- A child sees the internals of its ancestors (`Billing.Invoices` may use `Billing\Services\Money`, even an `#[Internal]` class), as it belongs to them. The parent using an internal class of a child is reported.
- `depends_on` of a child names its siblings and the modules outside the parent. A parent uses its children, and a child its parent, without declaring it. Naming a child from outside is a configuration error.

**Rules.**
- `cycles` are found between full names. The use between a parent and its child is containment and never closes a cycle.
- `layers` and `unused_dependency` look at the visible modules, and skip the use between a parent and its descendants. A nested module that `layers` does not name takes the layer of its nearest ancestor that it names, as source and as target of a dependency. Naming the child gives it its own layer. This is the inheritance `external_dependency` and `tables` already use (ADR 0026, 0027).
- `public_api_leak` checks every viewpoint: a public class of a child that exposes an internal one is reported whether it is public to its siblings or to the whole app.
- `host_access` treats the code outside the modules like any module outside the parent.
- Current behaviour, recorded as it is: `internal_access` and `host_access` report the deepest module as target module (the one that owns the class), while `undeclared_dependency` and `layers` report the visible one (what the source module sees). The baseline key of `internal_access` holds the class and the one of `undeclared_dependency` holds the visible module, and the `target_module` field of the report follows the same split.

**Output.** The baseline, the reports and `cordon:modules` (which lists children under their parent) use the full names. `cordon:verify --module Billing` keeps the violations of `Billing.Invoices` too, since from outside they are part of `Billing`, and `--module Billing.Invoices` keeps only its own. `cordon:graph` draws a nested module inside a subgraph (Mermaid) or cluster (DOT) of its parent. `cordon:affected` brings the ancestors of an affected module and all the descendants of every affected module (ADR 0025).

## Consequences
- A project without `nested` is unchanged: every new field is optional and no module has a parent.
- `Module::$parent` and the `ModuleMap` methods are part of the classes marked `@api`, so they follow the compatibility page.
- A child may call its parent's internals, which is a convention borrowed from Spring Modulith. A team that wants the opposite cannot configure it yet.
- The `target_module` of a violation depends on the rule (see above). Normalising it would change report fields and is left for a later ADR.
