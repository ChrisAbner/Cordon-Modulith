# Propuesta de charla (CFP, borrador)

Para Laracon, Laravel Live o meetups locales. Formato: 25–30 minutos. En inglés; versión en español para meetups hispanohablantes.

---

**Title:** Your modules are just folders: verifying boundaries in a Laravel modular monolith

**Abstract (short):**
Modular monoliths promise independent modules inside one Laravel app. In practice, one `use` statement at a time, every module ends up depending on every other one. This talk shows how to make a module's public API explicit (contracts, data objects, events), how to verify it automatically with static analysis, and how to adopt it in a large existing codebase without stopping feature work.

**Description:**
1. The problem (5 min): a real modular Laravel app after a year. Coupling you can't see in the folder tree: cross-module Eloquent relations, jobs calling other modules' services, event cycles.
2. Public API of a module (7 min): conventions that scale (`Contracts`, `Data`, `Events`), what to do with models, shared kernels, and the `#[PublicApi]` escape hatch.
3. Verifying it (8 min): how static analysis maps every class reference to a module (nikic/php-parser, no autoloading), the three rules (internal access, undeclared dependencies, cycles), live demo in CI with annotations. Honest comparison with Deptrac and Pest `arch()`.
4. Adoption (5 min): baselines, one module per sprint, Pest and PHPStan integrations, AI coding agents that respect boundaries.
5. Living documentation (3 min): generating dependency diagrams and an event inventory from the code.

**Takeaways:** a checklist for defining module APIs, a strategy to break cycles with events or dependency inversion, and a way to stop boundaries from eroding on every pull request, with any tool.

**Audience:** intermediate to advanced Laravel developers working on medium or large applications.

**Speaker notes:** the tool used in the demo is open source (Cordon Modulith), but the ideas apply to any tool; the talk is not a product pitch. Not affiliated with Laravel.

**Links:** https://github.com/ChrisAbner/Cordon-Modulith · https://chrisabner.github.io/Cordon-Modulith/
