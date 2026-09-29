# Comparison

Cordon Modulith is not the only way to keep a Laravel codebase in shape. Here is an honest comparison to help you choose, or combine.

## Deptrac

[Deptrac](https://github.com/deptrac/deptrac) is the reference static architecture checker for PHP. You define layers with collectors (by namespace, directory, class name, inheritance...) and which layers may depend on which.

- **What it does well:** framework-agnostic, very flexible, mature, fast, with caching, a baseline and several output formats. It can express layered rules *inside* modules.
- **What Cordon Modulith adds:** module discovery from nwidart, InterNACHI or `app/Modules` with no layer definitions; a public API model per module (public namespaces, `#[PublicApi]`/`#[Internal]`, open modules) instead of "may depend on the whole layer"; cycle detection with the shortest path; Laravel integration (Artisan, Pest expectation, Boost skill, living documentation).
- **Choose Deptrac** when you need layered rules or you are not on Laravel. You can use both: Deptrac for layers inside modules, Cordon Modulith for the edges between them.

## Pest `arch()`

[Pest's architecture testing](https://pestphp.com/docs/arch-testing) ships with Laravel's default test setup.

- **What it does well:** zero setup, readable expectations (`expect('App\Models')->toExtend(Model::class)`), presets, great for conventions *inside* a module.
- **Limits for boundaries:** expectations rely on reflection, so they only see classes that exist and can be autoloaded. They express namespace-to-namespace rules, so the notion of a module's public API, per-module `depends_on`, cycles and a baseline are left to you.
- **Use both:** `arch()` for conventions, `expect('Billing')->toRespectBoundaries()` for module boundaries.

## laravel-true-modular

[happenv-com/laravel-true-modular](https://github.com/happenv-com/laravel-true-modular) is a modular framework for Laravel: every module is a Composer package with declared dependencies, a deterministic boot order and extra lifecycle phases. Its companion PHPStan package detects undeclared cross-module references and cycles, and it provides commands such as `module:graph`, `module:why` and `module:impact`.

- **What it does well:** it owns the whole module lifecycle, so dependencies are declared once in `composer.json` and enforced at boot and in static analysis. Good fit for greenfield projects that want strong structure.
- **Differences:** Cordon Modulith does not create, register or boot modules: it is a dev dependency that works on top of the layout you already have (nwidart, InterNACHI, folders). It adds a public API model inside each module, a baseline for gradual adoption in existing projects, a Pest expectation and generated documentation.
- **Choose laravel-true-modular** when you want a framework for modules, not only verification. Check its documentation for its current feature set.

## Packwerk and Spring Modulith

Cordon Modulith borrows from both: Packwerk's per-package dependencies and `package_todo.yml` (our `depends_on` and baseline), and Spring Modulith's named interfaces, verification in tests and generated documentation.
