## Cordon Modulith (module boundaries)

This application uses Cordon Modulith to enforce boundaries between its modules. Follow these rules when writing or changing code:

- A module may only use another module's public API: classes under its public namespaces at any depth (by default `Contracts`, `Events`, `Data`, `Enums` and `Exceptions`) or classes marked with `#[\Cordon\Attributes\PublicApi]`.
- Never use models, services, jobs, actions or other internal classes from a different module. Add a contract, event or data object to the target module's public API instead, and depend on that.
- To react to something that happens in another module without depending on it, listen to one of its public events.
- If a module declares `depends_on` in `config/cordon.php`, only add a new module dependency there when it is intended, and never add it just to silence a violation.
- Avoid dependency cycles between modules; invert one side with an event or a contract.
- Run `php artisan cordon:verify` after changes that touch more than one module and fix the violations. Do not add new violations to the baseline file.
