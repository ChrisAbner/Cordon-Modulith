---
layout: home

hero:
  name: Cordon Modulith
  text: Cordon off your modules
  tagline: Verifiable boundaries between the modules of a Laravel application, checked in CI.
  actions:
    - theme: brand
      text: Get started
      link: /guide/introduction
    - theme: alt
      text: View on GitHub
      link: https://github.com/ChrisAbner/Cordon-Modulith

features:
  - title: Public API per module
    details: Other modules may only use a module's contracts, events, data objects, enums and exceptions, or classes you mark with #[PublicApi].
  - title: Works with your layout
    details: nwidart/laravel-modules, InterNACHI/modular or a plain app/Modules folder, detected automatically. Nothing to scaffold.
  - title: Static, never runs your code
    details: Parses files with nikic/php-parser. Works on code that doesn't boot and classes that don't exist yet. 1,000 files in about a second.
  - title: Adopt gradually
    details: Record existing violations in a baseline and fail the build only on new ones.
  - title: Where you already work
    details: GitHub annotations, a Pest expectation, a PHPStan rule for your editor and a Laravel Boost skill for AI agents.
  - title: Living documentation
    details: Mermaid diagrams, a canvas per module and an event inventory, generated from the code.
---

```text
$ php artisan cordon:verify

Cordon analysed 212 files in 6 modules (148 cross-module references).

x [internal_access] Modules/Billing/app/Services/CheckoutService.php:13
  Module [Billing] uses Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).

x [cycles]
  Modules [Billing, Catalog] form a dependency cycle: Billing -> Catalog -> Billing. Break it by inverting one dependency, for example with an event or a contract.

2 boundary violations.
```
