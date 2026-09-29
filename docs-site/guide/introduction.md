# Introduction

Modular monoliths in Laravel usually rely on [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules), [InterNACHI/modular](https://github.com/InterNACHI/modular) or a hand-made `app/Modules` folder. All of them organise code, but none of them stops the `Billing` module from reaching into `Catalog`'s models. Six months later the "modules" are just folders.

Cordon Modulith reads your code statically, builds the dependency graph between modules and fails the build when a module:

- uses **internal classes** of another module instead of its public API (`internal_access`),
- depends on a module it **did not declare** (`undeclared_dependency`), or
- takes part in a **dependency cycle** (`cycles`).

It is inspired by [Spring Modulith](https://spring.io/projects/spring-modulith) and Shopify's [Packwerk](https://github.com/Shopify/packwerk), adapted to Laravel conventions.

## What it is not

- It does not create, register or load modules. Keep using nwidart, InterNACHI or your own folders.
- It does not run at request time. It is a dev dependency for your terminal, CI, tests and editor.
- It does not check rules inside a module (for example "controllers must be final"). Pest's `arch()` and PHPStan are great at that.

## How it works

1. A **resolver** finds your modules (name, namespace, path).
2. Every PHP file inside a module is parsed with [nikic/php-parser](https://github.com/nikic/PHP-Parser). Your code is never loaded or executed.
3. Every class reference (`new`, static calls, type declarations, `extends`/`implements`, traits, attributes, `instanceof`, `catch`, `::class`...) is mapped to the module that owns it by namespace. Import statements alone, function calls and constants don't count.
4. References that cross a module boundary are checked by the **rules**.
5. Known violations can be ignored with a **baseline**.

Next: [install it](./installation).
