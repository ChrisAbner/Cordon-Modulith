# ADR 0018: Version the JSON report and open its schema

**Status:** accepted
**Date:** 2026-11-08

## Context
The JSON report is read by scripts and dashboards, and it had no version and no schema. Severities (ADR 0015), the link to each rule's documentation and later additions needed a rule for what counts as a compatible change. A schema closed on every object (`additionalProperties: false`) would make each new field a major version.

## Decision
- The report starts with `"version": 1`. Its schema is published in the package as `resources/schemas/report-v1.schema.json`, and the tests validate the reports against it.
- Adding an optional field is compatible and keeps version 1: every object of the schema accepts fields it does not list. Removing or renaming a field, or changing its type, is a new version.
- Version 1 has `summary` (`modules`, `files`, `cross_module_references`, `violations`, `warnings`, `suppressed`, `ignored`, `parse_errors`), `violations`, `warnings` and `parse_errors`. Each violation has `rule`, `severity` (`error` or `warn`), `message`, `file`, `line`, `source_module`, `target_module`, `target` and `docs_url` (null for custom rules). `summary.ignored` and `docs_url` are optional in the schema, so a version 1 report written before they existed stays valid.
- Paths are relative to the project root. A file outside it, such as a package of a monorepo, is written with `../`.
- `cordon:verify` writes three more formats for CI tools: `--format=gitlab` (GitLab Code Quality), `junit` and `checkstyle`. The text of messages and the layout of the `text` format are not part of the contract.
- `cordon:explain`, `cordon:why`, `cordon:impact` and `cordon:graph` have their own `--format=json`, which is not covered by this schema.

## Consequences
- Consumers can rely on version 1 and ignore what they do not know.
- Paths outside the project root no longer differ between machines. Baseline entries for those files (they were absolute) become stale: regenerate the baseline once with `--generate-baseline`.
- A change that removes or renames a field costs a `report-v2.schema.json`, and both versions have to be supported for a while.

## Covers
- Options: `cordon:verify --format=gitlab|junit|checkstyle`, the `--format` of the explore commands (ADR 0020).
- Report: `version`, `warnings`, `summary.warnings`, `summary.ignored`, `severity`, `docs_url`.
- File: `resources/schemas/report-v1.schema.json`.
