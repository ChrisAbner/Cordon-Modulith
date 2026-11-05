# ADR 0013: Shrink the baseline without accepting new violations

**Status:** accepted
**Date:** 2026-11-05

## Context
ADR 0004 lets fixed violations stop matching, and the docs suggested regenerating the baseline from time to time to shrink it. Regenerating records every current violation, so it also accepts, without notice, any violation that slipped in meanwhile (CI off, a merge with the check red). The Boost skill told agents the same. And nothing said that an entry no longer matched, so baselines only grew unless someone regenerated them.

## Decision
- `cordon:verify` counts the baseline entries that record more occurrences than the code has, on the whole analysis (before `--module`), and reports them: on stderr for `text` and `json`, so the JSON stays valid, and as a `::notice` for `github`. The exit code does not change.
- `--prune-baseline` rewrites the baseline without those occurrences: it removes entries and lowers counts, and never adds one. It prints how many entries were pruned and how many violations are not in the baseline, then exits with 0, like `--generate-baseline`.
- It cannot be combined with `--module` (pruning needs the whole analysis), `--generate-baseline` or `--no-baseline`, and it needs an existing baseline file. Those cases exit with 2.
- The baseline format does not change. A pruned version 1 baseline is saved as version 2; its old `cycles` entries match nothing, so they are removed.
- The Boost skill tells agents to prune, never to regenerate, after fixing violations.

## Consequences
- A baseline can be shrunk safely, and teams see when it is worth doing.
- `--strict-baseline` (fail when entries are stale, so the baseline can only shrink) is left for later.
