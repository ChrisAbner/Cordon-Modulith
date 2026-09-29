# Mensaje a mantenedores de nwidart e InterNACHI (borrador)

Enviar como Discussion (o issue si no hay Discussions) en cada repo, **después** de publicar la v0.1 y con el sitio de docs en línea. Adaptar el nombre del paquete y un ejemplo con su estructura. Tú lo envías, no los agentes.

---

**Title:** Complementary package: verifying boundaries between modules

Hi! Thanks for maintaining {nwidart/laravel-modules | InterNACHI/modular}. I've been using it to structure a modular monolith, and I built a small dev package that complements it rather than replacing anything: [Cordon Modulith](https://github.com/ChrisAbner/Cordon-Modulith).

It reads your module layout (it detects {`module.json` and `config/modules.php` | `app-modules/*/composer.json`} automatically), parses the code statically and reports when a module uses another module's internal classes, depends on a module it didn't declare, or is part of a dependency cycle. It never creates, registers or boots modules.

I'm not asking for anything in particular, but a few ideas in case they're useful:

- If you think it fits, a link in the docs section about organising modules ("verifying boundaries between modules") could help users who ask how to keep modules independent.
- If there are layout details I'm getting wrong ({custom paths, namespaces, `app/` folder | several PSR-4 roots per module}), I'd love to fix them. There are fixtures for your layout in `tests/Fixtures/`.
- If you ever consider native boundary checks, I'd be happy to share what I learned or contribute.

Thanks again for your work.
