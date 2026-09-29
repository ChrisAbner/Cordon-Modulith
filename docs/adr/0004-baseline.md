# ADR 0004: Baseline for gradual adoption

**Status:** accepted

## Context
Existing projects will start with many violations. Without a way to accept them, nobody can add Cordon Modulith to CI.

## Decision
`cordon:verify --generate-baseline` writes a JSON file of known violations keyed by rule, relative file and target, with an occurrence count. Line numbers are excluded so unrelated edits don't invalidate entries. Only as many occurrences as recorded are suppressed.

## Consequences
- The build fails only on new violations.
- A violation moved to another file is reported again (intended).
