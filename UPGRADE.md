# Upgrading

## From 0.3 to 0.4

1. **Run `cordon:verify` once and fix any configuration error.** `config/cordon.php` is now validated: unknown keys (`depend_on`), unknown modules, dependencies on modules that do not exist and unknown rule ids exit with code `2` and name the closest valid key. These were silently ignored before, so fixing them may change what is checked. `null` is no longer accepted where a list or a setting is expected: `'modules' => null`, a module set to `null` (`'Shared' => null`) and a rule set to `null` or `1` are errors; remove the entry, or use `[]`, `true`/`false` or `error`/`warn`/`off`. Two custom rules with the same id also exit with code `2`.
2. **Check your cycles.** A listener of another module's event no longer closes a cycle. If your baseline recorded such cycles, `cordon:verify` now says those entries no longer match: remove them with `cordon:verify --prune-baseline`. To keep the 0.3 behaviour, set:

   ```php
   'listeners' => ['cycles' => true],
   ```
3. **Optional: switch rules to severities.** `true` and `false` keep working. To adopt a rule gradually, set it to `'warn'`: its violations are reported but do not fail.
4. **If you parse the JSON report**, it now starts with `"version": 1`, adds `summary.warnings`, a `warnings` list and a `severity` on every violation. Nothing was removed or renamed. The schema is `resources/schemas/report-v1.schema.json` in the package.
5. **If you wrote custom rules**, the classes they use are marked `@api` and are covered by the [compatibility](https://chrisabner.github.io/Cordon-Modulith/guide/compatibility) page. Classes marked `@internal` (such as `Analyzer` or `RuleSet`) may change in any release.
6. `nikic/php-parser` 5.3 or later is required. Composer upgrades it when you update Cordon.
7. **If some modules live outside the project root** (a monorepo with `../packages`), their files are now written with `../` in reports and in the baseline, instead of an absolute path. Regenerate the baseline once with `cordon:verify --generate-baseline`.
8. **If you tried `layers` from a development version before 0.4.0**, its baseline entries are now per class (ADR 0014): the target is the class that crosses the layer, not the module. Regenerate the baseline once with `cordon:verify --generate-baseline`. Releases before 0.4 had no `layers`, so nothing else changes.
