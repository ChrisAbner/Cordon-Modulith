# ADR 0017: Check the code outside the modules as a `host`

**Status:** accepted
**Date:** 2026-11-08

## Context
Cordon analysed only the files of the modules. Controllers, Filament resources, routes and migrations that live outside them, and that reach into a module's internals, were not checked at all (#25). The question was whether the code outside the modules should be a module with its own rules or a source that only has to respect the public APIs.

## Decision
- The code outside the modules is the `host`. It is not a `Module`: it is never in a `ModuleMap`, in the graph, in `depends_on`, in `layers`, in cycles or in `--module`. It is only the source of a rule.
- A new rule, `host_access`, reports a file of the host that uses a class which is internal to a module, once per file and class, like `internal_access`. Its violations have `source_module` set to `host`. It starts in `warn` (ADR 0015).
- A new config key lists the directories, relative to the project root:

  ```php
  'host' => ['paths' => ['app', 'routes', 'database']],
  ```

  That is the default, also for a published config that has no `host` key. Files of a module inside those directories still belong to their module. `'paths' => []` leaves the host unchecked and keeps the counts of 0.3. Only `paths` is a valid key.
- A module cannot be named `host` while `host.paths` is set: it is a configuration error, so `sourceModule` stays unambiguous.
- `host` is not a valid module name in `layers` (it is an unknown module there), and `host.strict` is not supported.
- The host files count in `summary.files` and in the text report, and a host file that does not parse is listed as skipped.
- `AnalysisContext::$hostEdges` carries the references from the host to a module, and `Module::host()` and `Module::HOST` name the source.
- The PHPStan rules do not report `host_access` in 0.4, since they report only rules set to `error`.

## Consequences
- Projects that use the default see warnings from `host_access` after upgrading, never failures. `host.paths` or `rules.host_access` turn it down.
- `summary.files` and the file count change by the number of host files.
- `--module X` shows what the code of module X does, not the host code that uses X: the host is the source of its violations and it is not a module. `explain`, `why` and `impact` do not list host uses.
- A later "host in the graph" would need its own code.

## Covers
- Config: `host.paths`, `rules.host_access`.
- Types: `AnalysisContext::$hostEdges`, `Module::host()`, `Module::HOST`.
- Report: `source_module: "host"`, the rule id `host_access`.
