# ADR 0011: Report cycles per dependency

**Status:** accepted

## Context
`cycles` reported one violation per group of modules that reach each other (a strongly connected component). Its target was the shortest cycle from the group's first module in alphabetical order. The baseline (ADR 0004) keys entries by rule, file and target, so once a group was recorded, a module joining it or a new dependency inside it rarely changed the target and stayed suppressed. In existing apps there is usually one large group, so `cycles` stopped guarding right after adoption.

## Decision
Report one violation per dependency between two modules of the same group, with target `From -> To`, source and target module set, and a message with the shortest cycle that dependency closes. The baseline format moves to version 2. Loading a version 1 file with `cycles` entries warns that they no longer match and asks to regenerate the baseline.

## Consequences
- A module joining a recorded group, or a new dependency inside it, is a new violation.
- Removing one dependency removes its violation only; the rest of the group stays suppressed.
- More reports: two modules that depend on each other give two, and a large group gives one per dependency.
- Upgrading needs one `--generate-baseline` run if the baseline has `cycles` entries.
- `--module` keeps showing the cycles a module takes part in, because every module in a group has dependencies in and out of it.
