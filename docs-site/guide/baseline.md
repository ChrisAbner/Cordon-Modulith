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

To keep the baseline from going stale, pass `--fail-on-stale` in CI. A stale entry then fails the check, and each one is listed with the occurrences it records and the ones the code still has:

```text
1 baseline entry no longer matches the code. Run php artisan cordon:verify --prune-baseline to remove it.
  - [internal_access] app/Modules/Billing/Services/CheckoutService.php -> App\Modules\Catalog\Models\Product: expected 3, found 1
```

When a file does not parse, its references are missing and its entries would look stale, so `--fail-on-stale` skips the check and says so. `--fail-on-stale` cannot be combined with `--no-baseline`.

## Strict modules

A module with `'strict' => true` in `config/cordon.php` never gets new debt: the baseline does not suppress its violations (the ones it causes, and the cycles it takes part in), and `--generate-baseline` does not record them and says how many it left out. Turn it on for a module once it is clean.

## Debt or decision: `@cordon-ignore`

The baseline records debt: violations you mean to fix, which `--prune-baseline` removes once they are gone. A violation you accept on purpose is a decision, and it belongs next to the code, with the reason:

```php
public function product(): Product // @cordon-ignore internal_access (the invoice PDF shows the product name)
{
    // ...
}

// @cordon-ignore internal_access, undeclared_dependency (prices are copied into the invoice when it is issued)
public function price(Price $price): void {}
```

A comment (`//`, `#` or `/* */`, not a docblock) that starts with `@cordon-ignore` accepts the violations of the rules it names. At the end of a line of code, it covers that line only. On a line of its own, it covers the statement below it, to its `;` or `{`, so a signature or a call split over several lines is covered. Cordon reports a violation at the first use of a class in a file (not at its `use` import): put the comment there. Then:

- The reason in parentheses is required (it may contain parentheses). Without it, without a rule, or with a rule that does not exist, `cordon:verify` stops with exit code `2`, like a config error, and names the file and line.
- `cycles` and `unused_dependency` report no line, so they cannot be ignored in place: record them in the baseline.
- A comment that no longer matches a violation is a warning (`... no longer matches a violation; remove it.`), so stale decisions do not pile up.
- Accepted violations are counted in the summary (`2 ignored in place`, `summary.ignored` in JSON) and never reach the baseline. The PHPStan rules honour the same comments.

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
