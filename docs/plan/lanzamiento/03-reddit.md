# Post para r/laravel (borrador)

Tono: problema real primero, sin tono de anuncio. Responder a todos los comentarios durante las primeras horas.

---

**Title:** How do you stop modules from using each other's models?

We split a Laravel app into modules (nwidart, but the same happens with InterNACHI or plain folders). A year later, `Orders` had `belongsTo(\Modules\Catalog\Models\Product::class)`, `Payments` called back into `Orders`, and renaming a column in Catalog broke three other modules. Code review didn't catch it because each change looked reasonable on its own.

Pest's `arch()` helped for conventions, but for boundaries we wanted something that:

- knows what each module exposes (contracts, events, data objects) vs what is internal;
- doesn't need to load or autoload the code;
- lets a big existing app adopt it without fixing 300 violations first.

So I built a small dev package for it: it parses the code, maps every class reference to a module and reports internal access, undeclared dependencies and cycles.

```text
x [internal_access] Modules/Orders/app/Models/Order.php:13
  Module [Orders] uses Modules\Catalog\Models\Product, which is internal to module [Catalog]. ...

x [cycles]
  Modules [Orders, Payments] form a dependency cycle: Orders -> Payments -> Orders. ...
```

It has a baseline (only new violations fail), a Pest expectation, a PHPStan rule and can generate Mermaid diagrams of the real dependencies. Repo: https://github.com/ChrisAbner/Cordon-Modulith (docs: https://chrisabner.github.io/Cordon-Modulith/). It's early (0.1), so I'd love to hear:

- how you handle this today (Deptrac? discipline? nothing?);
- layouts it would get wrong in your projects.
