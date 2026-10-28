# Case studies

We ran Cordon Modulith on three open source Laravel applications, one for each kind of module structure it supports. The goal was to check that it works on real code, find false positives, and see what kind of coupling grows in real modular monoliths.

| Application | Structure | Modules | Files | Violations (default config) | Violations (public API declared) | Time |
|---|---|---|---|---|---|---|
| [Bagisto](./bagisto) | Namespaces (`packages/Webkul/*`) | 42 | 1,997 | 920 | 159 | 6.1 s |
| [Advising App](./advisingapp) | InterNACHI/modular | 37 | 2,463 | 1,163 | 1,155 | 4.5 s |
| [LaravelEcomm](./laravelecomm) | nwidart/laravel-modules | 29 | 1,098 | 778 | 396 | 2.7 s |

All three were analysed in October 2026 on Laravel 13 and PHP 8.4. Each page lists the exact commit.

## What we found

- **No false positives.** We checked every one of the 2,858 `internal_access` reports by machine: the reported line contains the class (or its import alias), and the class is declared in the module Cordon named. We then read a sample of each kind by hand. Every report pointed at a real reference: inheritance, constructor injection, static calls, Eloquent relations or `instanceof`.
- **Most of the first report is configuration, not coupling.** Out of the box, Cordon only treats `Contracts`, `Events`, `Data`, `Enums` and `Exceptions` as public. Real apps share more on purpose: a `Core` module every other module extends, repositories meant as the access layer, facades. Declaring those removed 83% of Bagisto's report and half of LaravelEcomm's. See [what we declared and why](#how-we-declared-the-public-api).
- **What remains is real.** A handful of models used by almost every module (`User` in 25 of 29 modules in LaravelEcomm, `Student` in 21 of 37 in Advising App), order status constants read straight from the `Order` model, and storefront code reaching into sales internals.
- **Cycles are the norm.** In all three apps, most modules belong to one large group that depends on itself: 30 of 42 modules in Bagisto, 29 of 37 in Advising App, 25 of 29 in LaravelEcomm. Counting direct back-and-forth pairs (`A` uses `B` and `B` uses `A`): 26, 54 and 34.
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
