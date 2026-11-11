# ADR 0026: External packages: `packages` and `external_dependency`

**Status:** accepted (2026-11-08, plan approved by the maintainer: everything seen goes in)

> The maintainer approved the plan for 0.5 on 2026-11-08 with the decision that everything seen goes in. The shape below is the plan's, as the code implements them.

## Context
Modules should depend on each other through public APIs, and also on the Composer packages they use: a `Billing` module that starts calling `GuzzleHttp` directly, or a `Domain` module that reaches for Eloquent, is a boundary the team may want to draw.

## Decision
- **Config.** `packages => ['shared' => [...]]` lists packages every module may use, and `packages` in the settings of a module lists the ones it may use besides. Entries are package names (`stripe/stripe-php`) or prefixes that end in `*` (`symfony/*`), compared without regard to case. A module without `packages` is not checked, and `packages => []` allows only the shared ones. A nested module without its own list takes the one of its parent. The code outside the modules (`host`) takes no `packages` and is not checked.
- **Rule.** `external_dependency` (default `error`, inactive until a module lists `packages`): `Module [Billing] uses Stripe\StripeClient from [stripe/stripe-php], which is not in its packages.`, once per file and package, at the first use. The `target` is the package and there is no target module. Every kind of reference counts (code, docblocks when on, listeners). `@cordon-ignore external_dependency` works as for other rules. The PHPStan rule does not report it.
- **Provable ownership.** Cordon reads `vendor/composer/installed.json` (or the `config.vendor-dir` of `composer.json`) as data and never the autoloader. A class belongs to a package only when an installed package provably provides it: the file of a PSR-4 or PSR-0 class exists in the package, and a classmap names its classes one by one, counting only what its files declare unconditionally inside a namespace. PHP's own classes, the polyfill stubs (declared under `if (PHP_VERSION_ID < ...)` or in the global namespace), a class that two packages declare, the classes of the project, and those of a package that is not installed belong to none and are never reported. For nested prefixes the package that holds the file wins, preferring the longest prefix.
- **`replace`.** A class belongs to the package that holds it, and a list that names that package or any package it replaces covers it. `laravel/framework` declares that it replaces `illuminate/*`, so listing `illuminate/support` also permits every class of `laravel/framework`. This is permissive by design: it never reports a class the list covers under another name.
- **Missing data.** When a module lists `packages` and `installed.json` is missing, `cordon:verify` stops with exit code `2`. A listed package that is not installed is a warning with the closest installed name.
- **Commands.** `cordon:why <module> <vendor/pkg>` lists the uses of a package by a module, and `cordon:explain <class>` says which package a class belongs to and who uses it.
- **Data.** `AnalysisContext::$externalReferences` lists the references in module files to a class no module owns, and `Module::$packages` holds the setting.

## Consequences
- A project without `packages` is unchanged.
- Only what Composer metadata proves is attributed, so some uses of a package are missed (a class two packages declare, a package that is not installed in the analysed environment). That follows the zero false positives rule.
- `replace` makes the rule permissive when a package bundles others.
- `AnalysisContext::$externalReferences` is readable by custom rules but its type is `@internal` and it is not part of the frozen 1.0 surface until this ADR is confirmed against the compatibility page (see the compatibility guide).
