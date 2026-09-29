# RFC para GitHub Discussions (borrador)

Categoría: Ideas. Publicar en inglés.

---

**Title:** RFC: verifiable module boundaries for Laravel modular monoliths

## The problem

Many Laravel teams split their application into modules with nwidart/laravel-modules, InterNACHI/modular or a plain `app/Modules` folder. The folders help, but nothing stops one module from using another module's models, jobs or services. After a few months, every module depends on every other one and the boundaries only exist in the folder tree.

## The proposal

Cordon Modulith is a dev dependency that checks those boundaries statically and fails CI when a module:

- uses classes that are internal to another module (anything outside its public API: `Contracts`, `Events`, `Data`, `Enums`, `Exceptions`, or classes marked `#[PublicApi]`);
- depends on a module it did not declare in `depends_on`;
- takes part in a dependency cycle.

It never loads your code (nikic/php-parser), works on top of your current layout, and has a baseline so existing projects can adopt it without fixing everything first. There is also a Pest expectation, a PHPStan rule, a Laravel Boost skill and a `cordon:docs` command that generates Mermaid diagrams and an event inventory.

```text
x [internal_access] app/Modules/Billing/Services/CheckoutService.php:13
  Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).
```

## Open questions

1. How do you verify module boundaries today? Deptrac, Pest `arch()`, code review, nothing?
2. Are the default public namespaces (`Contracts`, `Events`, `Data`, `Enums`, `Exceptions`) the right ones for your projects?
3. Should listening to another module's event count as a dependency for `depends_on`? (Today it does.)
4. Which layouts are we missing? (DDD with `src/Domain`, packages in `packages/`, ...)
5. Would you use the PHPStan rule, the Pest expectation or only the Artisan command in CI?

Feedback, especially "this would not work for us because...", is very welcome.
