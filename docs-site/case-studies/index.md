# Case studies

We ran Cordon Modulith on five open source Laravel applications, covering every kind of module structure it supports. The goal was to check that it works on real code, find false positives, and see what kind of coupling grows in real modular monoliths.

| Application | Structure | Modules | Files | Violations (default config) | Violations (public API declared) | Time |
|---|---|---|---|---|---|---|
| [Bagisto](./bagisto) | Namespaces (`packages/Webkul/*`) | 42 | 1,997 | 1,086 | 325 | 6.1 s |
| [Advising App](./advisingapp) | InterNACHI/modular | 37 | 2,463 | 1,363 | 1,355 | 4.5 s |
| [LaravelEcomm](./laravelecomm) | nwidart/laravel-modules | 29 | 1,098 | 904 | 522 | 2.7 s |
| [Krayin CRM](./krayin) | Namespaces (`packages/Webkul/*`) | 20 | 632 | 475 | 159 | 0.8 s |
| [Leantime](./leantime) | Plain folders (`app/Domain/*`) | 45 | 522 | 587 | 310 | 1.1 s |

Krayin and Leantime were added on 2026-11-12, analysed with Cordon 0.4.0 and `host.paths` set to `[]`, in a Docker container limited to 2 CPUs. The first three were analysed in October 2026 on Laravel 13 and PHP 8.4. Each page lists the exact commit. The violation counts are those of Cordon 0.2.0, which reports one `cycles` violation per dependency inside a cycle (167, 201 and 127 here); they were recomputed at the same commits on 2026-11-04. Before 0.2.0, each group of tangled modules was a single violation.

**Re-checked with 0.4.0 (2026-11-11).** At the same commits and with the same configs, 0.4.0 reports exactly the same errors as 0.3.1 in all three apps (`internal_access` and `cycles`, byte for byte). What changes is that 0.4.0 also analyses the code outside the modules by default (the [`host`](../guide/configuration) module: `app`, `routes`, `database`), so more files are read (2,012, 3,041 and 1,165) and two new kinds of warning appear. Warnings never fail the build: `host_access` (1, 216 and 114: code outside the modules using a module's internals) and `public_api_leak` (1, 37 and 1: a public class exposing an internal one). Setting `host.paths` to `[]` gives back the figures in the table.

## What we found

- **No false positives.** In the first three apps we checked every one of the 2,858 `internal_access` reports by machine: the reported line contains the class (or its import alias), and the class is declared in the module Cordon named. We then read a sample of each kind by hand. Every report pointed at a real reference: inheritance, constructor injection, static calls, Eloquent relations or `instanceof`. In Krayin and Leantime we read 25 and 20 reports at random by hand, with the same result.
- **Most of the first report is configuration, not coupling.** Out of the box, Cordon only treats `Contracts`, `Events`, `Data`, `Enums` and `Exceptions` as public. Real apps share more on purpose: a `Core` module every other module extends, repositories meant as the access layer, facades. Declaring those removed 83% of Bagisto's `internal_access` reports and half of LaravelEcomm's. See [what we declared and why](#how-we-declared-the-public-api).
- **What remains is real.** A handful of models used by almost every module (`User` in 25 of 29 modules in LaravelEcomm, `Student` in 21 of 37 in Advising App), order status constants read straight from the `Order` model, and storefront code reaching into sales internals.
- **Cycles are the norm.** In all five apps, at least half of the modules belong to one large group that depends on itself: 30 of 42 modules in Bagisto, 29 of 37 in Advising App, 25 of 29 in LaravelEcomm, 19 of 20 in Krayin and 24 of 45 in Leantime. Counting direct back-and-forth pairs (`A` uses `B` and `B` uses `A`): 26, 54, 34, 25 and 32.
- **Declaring a public API never removes a cycle.** It removes access reports, but in all five apps the cycle groups are exactly the same before and after. In Leantime some cycles go only through services, which are public there.
- **Fast enough for CI.** Between 3 and 6 seconds and under 160 MB of memory for 1,000 to 2,500 files, on a developer machine (8 cores, Docker on WSL2).

These are snapshots of how the code is organised, not judgments. Many of these dependencies are deliberate trade-offs, and none of these projects claims to enforce module boundaries. They show what happens when boundaries are only a folder convention.

## How we declared the public API

For each app we read the code and declared as public only what is clearly meant to be shared:

- an `open` shared kernel when every module extends its base classes ([configuration](../guide/configuration)),
- extra `public_namespaces` for layers the app uses as its cross-module API (repositories, facades, DTOs),
- individual classes through the per-module `public` list.

This is our reading of each codebase, not a statement from its maintainers. Both numbers are on each page, so you can judge the difference yourself.

## Reproducing

Every run used the same steps:

```bash
git clone --depth 1 https://github.com/<owner>/<app>.git && cd <app>
composer install
composer require --dev chrisabner/cordon-modulith
php artisan cordon:modules
php artisan cordon:verify --no-baseline --format=json > report.json
```

The configuration used for each app is on its page.
