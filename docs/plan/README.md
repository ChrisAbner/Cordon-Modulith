# Plan del proyecto Cordon Modulith

Documentación interna de planificación (en español). La documentación pública del paquete está en inglés en la raíz del repo.

## Estado actual (MVP 0.1)

| Pieza | Estado |
|---|---|
| Estructura del repo, CI, Pint, PHPStan, Pest | Hecho |
| Contratos del núcleo (`src/Contracts`) | Hecho, congelados |
| Resolvers: namespace, nwidart, InterNACHI + autodetección | Hecho |
| Extracción estática con nikic/php-parser | Hecho, con fixtures de casos límite |
| Reglas: `internal_access`, `undeclared_dependency`, `cycles` | Hecho |
| Baseline | Hecho |
| Reporters: text, json, github | Hecho |
| Comandos `cordon:verify` (con `--module`), `cordon:modules`, `cordon:docs` | Hecho |
| Guideline y skill de Laravel Boost | Hecho |
| Tests unitarios y de feature | **Ejecutados: 91 en verde** (PHP 8.4, Laravel 13 y 12), `composer check` en verde |
| Fixtures de 5 proyectos reales | Hecho (`tests/Fixtures/real-*`) |
| Rendimiento | 1.000 archivos en ~1,1 s; 5.000 en ~5,5 s (`composer bench`) |
| Integraciones: Pest, PHPStan, GitHub Action | Hecho (ADR 0005–0007 propuestos) |
| Sitio de documentación (VitePress) | Hecho (`docs-site/`), falta publicarlo en GitHub Pages |
| Documentación viva (`cordon:docs`) | Hecho (ADR 0008 propuesto) |
| API de reglas propias | Hecho (ADR 0009 propuesto) |
| Materiales de lanzamiento y guion de vídeo | Borradores en `lanzamiento/` y `video.md` |
| Nombre final y vendor de Composer | Decidido: **Cordon Modulith**, paquete `chrisabner/cordon-modulith`. Falta verificar marcas (Fase 0) |

**Pendiente de ti** (detalle paso a paso en [siguientes-pasos.md](siguientes-pasos.md)): aprobar o rechazar los ADR 0005–0009 (estado "proposed"), verificar marcas, probar a mano en 3 proyectos reales, crear el repo `cordon-demo` (brief 03B), publicar en Packagist y los materiales de lanzamiento. El plugin de Filament (6B) y la v1.0 (6C) quedan para después de la v0.x.

## Cómo trabajar con enjambres de agentes

1. Cada fase tiene un brief en `enjambres/` con objetivo, archivos permitidos, tareas, tests de aceptación, definición de terminado y un prompt listo para pegar.
2. Los agentes leen primero `AGENTS.md`, `docs/architecture.md` y los proyectos de `referencias.md` marcados para su brief.
3. Un enjambre no toca archivos fuera de su alcance. Tú integras y mergeas.
4. Cualquier cambio a contratos, formato de config, baseline u opciones de comandos requiere un ADR aprobado por ti.
5. Una fase se cierra solo cuando `composer check` pasa en CI y se cumple su definición de terminado.

## Índice

- [siguientes-pasos.md](siguientes-pasos.md): checklist para publicar y lanzar la v0.1.0.
- [roadmap.md](roadmap.md): fases, calendario y criterios de salida.
- [referencias.md](referencias.md): proyectos de referencia y qué estudiar de cada uno por brief.
- [enjambres/00-fundacion.md](enjambres/00-fundacion.md)
- [enjambres/01-verificacion-nucleo.md](enjambres/01-verificacion-nucleo.md)
- [enjambres/02-integraciones.md](enjambres/02-integraciones.md)
- [enjambres/03-docs-e-ia.md](enjambres/03-docs-e-ia.md)
- [enjambres/04-lanzamiento.md](enjambres/04-lanzamiento.md)
- [enjambres/05-documentacion-viva.md](enjambres/05-documentacion-viva.md)
- [enjambres/06-ecosistema.md](enjambres/06-ecosistema.md)
- [lanzamiento/](lanzamiento/README.md): borradores de lanzamiento (brief 04).
- [video.md](video.md): guion de vídeo (brief 03D).
- [originales/](originales/): archivos originales entregados al inicio (`cordon.zip`, `README.md`, `PLAN.md`, `referencias.md`), sin modificar.
