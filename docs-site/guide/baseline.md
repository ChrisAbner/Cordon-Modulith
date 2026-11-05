# Adopting in an existing project

Existing codebases usually start with many violations. Record them in a baseline so the build only fails on **new** ones, then burn the baseline down over time:

```bash
php artisan cordon:verify --generate-baseline   # writes cordon-baseline.json
php artisan cordon:verify                       # passes; new violations fail
php artisan cordon:verify --prune-baseline      # drops entries you fixed; never adds
php artisan cordon:verify --no-baseline         # shows everything again
```

Commit `cordon-baseline.json`.

## How entries are matched

Entries are keyed by **rule, file and target** (the class, the module, or for `cycles` the dependency such as `Orders -> Payments`), with an occurrence count. Line numbers are left out, so unrelated edits don't invalidate them.

- Moving a violation to another file reports it again. This is intended: new code should not inherit old exceptions.
- Only as many occurrences as recorded are suppressed.
- Fixed violations stop matching. `cordon:verify` says how many entries no longer match, and `--prune-baseline` removes them (see [shrinking the baseline](#shrinking-the-baseline)).

```json
{
    "version": 2,
    "violations": [
        {
            "rule": "internal_access",
            "file": "Modules/Orders/app/Models/Order.php",
            "target": "Modules\\Catalog\\Models\\Product",
            "count": 1
        }
    ]
}
```

## Shrinking the baseline

When you fix a violation that was in the baseline, its entry stops matching and `cordon:verify` reports it (as a notice with `--format=github`):

```text
2 baseline entries no longer match the code. Run php artisan cordon:verify --prune-baseline to remove them.
```

`--prune-baseline` removes those entries and lowers counts. It never adds an entry, so a violation that slipped in while CI was off stays reported. Don't shrink the baseline with `--generate-baseline`: it records every current violation, including new ones. `--prune-baseline` cannot be combined with `--module`, because it needs the whole analysis.

## Upgrading from a version 1 baseline

Cordon 0.1 recorded one `cycles` entry per group of modules, so a module joining that group stayed hidden. Version 2 records one entry per dependency inside a cycle. A version 1 baseline still works for the other rules, but its `cycles` entries no longer match: `cordon:verify` reports those cycles again and warns about it. Regenerate the baseline once after upgrading (`--prune-baseline` only removes the old entries; it does not record the new ones):

```bash
php artisan cordon:verify --generate-baseline
```

## A practical adoption plan

1. Install, check `cordon:modules`, and fix the resolver if needed.
2. Mark your shared kernel as `open`, and add `public` entries for classes that are really meant to be shared.
3. Generate the baseline and add `cordon:verify` to CI.
4. Pick one module per sprint and fix its entries (`cordon:verify --no-baseline --module=Billing`).
5. Add `depends_on` to modules once their dependencies are clean.

Keep AI agents from growing the baseline: the [Boost skill](./ai-agents) tells them never to do it.
