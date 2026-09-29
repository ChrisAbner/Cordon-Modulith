# ADR 0010: Nested public namespaces

**Status:** accepted
**Date:** 2026-09-29

## Context
ADR 0003 defines the public API by namespaces relative to the module (`Contracts`, `Events`, `Data`, `Enums`, `Exceptions` by default). The first implementation matched only the first segment relative to the module root.

Field tests on real applications showed that about 20% of the `internal_access` reports in some apps were classes such as `He4rt\Recruitment\Requisitions\Enums\RequisitionStatusEnum` or `...\Requisitions\Events\JobRequisitionGenerationEvent`. Their relative name starts with a feature folder (`Requisitions`), so they were reported, but users see enums and events as public API wherever they live.

## Decision
- An entry of the global `public_namespaces` list matches at any depth: a class is public when the entry appears as a contiguous run of whole segments in its namespace relative to the module (the short class name is excluded). Multi-segment entries such as `Http\Resources` match the same way.
- With module root `Modules\Billing`: `Contracts\Charges`, `Invoices\Enums\Status` and `Invoices\Events\Sub\InvoicePaid` are public; `EnumsHelper\Foo`, `Invoices\MyEnums\Status` and `Invoices\Status` are internal.
- A class whose relative name is exactly an entry at the module root (`Modules\Billing\Enums`) stays public, as before.
- The per-module `public` list (`modules.X.public`) keeps its root-anchored behaviour: it names specific classes or namespaces such as `Services\BillingService`.
- Precedence is unchanged: `#[Internal]` > `#[PublicApi]` > open module > public namespaces > internal. `#[Internal]` remains the way to keep a nested `Enums` or `Events` class internal.
- No change to the config shape, the contracts or the baseline format.

## Consequences
- Fewer reports, in line with the zero-false-positives rule: nested enums, events, data objects, contracts and exceptions are no longer flagged.
- Possible under-reporting when a team meant a nested `Events` (or similar) namespace to be internal. Mark those classes with `#[Internal]`.
- Existing baselines may contain entries for classes that are now public. They are harmless: `Baseline::filter()` only suppresses violations that are actually found, and unmatched entries are ignored. They can be dropped by regenerating the baseline with `--generate-baseline`.
