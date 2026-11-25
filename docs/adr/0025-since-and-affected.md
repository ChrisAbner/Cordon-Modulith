# ADR 0025: `--since` and `cordon:affected`

**Status:** accepted (2026-11-08, plan approved by the maintainer: everything seen goes in)

> The maintainer approved the plan for 0.5 on 2026-11-08 with the decision that everything seen goes in. The semantics below are the plan's, as the code implements them.

## Context
A pull request touches a few files, yet CI runs every check and every test. Cordon knows the dependency graph, so it can say which modules a change can affect and which test folders to run.

## Decision
- **Changed files.** `--since=<ref>` takes any git reference. The changes are what was committed since the branch forked from it (`git diff <ref>...HEAD`), plus what is staged, modified or not tracked yet, deleted files and both names of a rename included. Git runs with an argument list, never through a shell. A reference that does not exist, a folder that is not a git repository, or a reference that starts with `-` exits with `2`.
- **`cordon:verify --since`** is `verify <changed PHP files>`: the whole project is analysed, and only the violations of those files and the cycles their modules take part in are reported. It cannot be combined with paths, `--generate-baseline` or `--prune-baseline` (exit `2`).
- **`cordon:affected --since`** (and `affected` in `vendor/bin/cordon`) lists the modules with changed files and the ones that depend on them, directly or through others: the graph of `cordon:impact`. Each module has a `reason`: `changed`, `depends_on`, `parent` (it contains an affected nested module) or `nested` (a nested module of an affected one, siblings included: it can list one that is not really affected, but never misses a test). The code outside the modules counts as the module `host`: a change there affects only `host`, since nothing depends on it.
- **`--tests`** prints the test folders of the affected modules, one per line, only the ones that exist: the `tests` setting of the module (`host.tests` for the host), or else `tests/<Module>` (`tests/Billing/Invoices` for `Billing.Invoices`) and the `tests` folder inside the module. `'tests' => []` means none. A changed file under a test folder counts as a change of its module.
- **JSON.** `--format=json` starts with `"version": 1`, then `since`, `changed`, `affected` (`module`, `reason`, `through`) and, with `--tests`, `tests`. A new optional field keeps the version, as in ADR 0018.
- **Blind spot.** The semantics are edge-based: a module is affected when a file of it changed or when it depends, in the graph of the current code, on one that did. A change can create a violation in a file that did not change: a new migration that creates a table another module already queries (`tables`), a change in `packages` or `depends_on` in the config, a class that becomes internal. Neither `--since` nor `affected` sees it. The docs tell the user to keep a full `cordon:verify` and the full tests on the main branch or on a schedule.

## Consequences
- The commands are new and change no existing output.
- `GitChanges` shells out to `git`, the only place the analysis core does. It needs a repository and a reference that git can resolve (a shallow clone may not have `origin/main`).
- The over-inclusion of nested siblings is deliberate: it costs test time, never a missed test.
- The blind spot makes `--since` a way to go faster, not a replacement for the full run.
