# Plan del proyecto Cordon Modulith

Documentación interna de planificación (en español). La documentación pública del paquete está en inglés en la raíz del repo.

## Estado actual (MVP 0.1)

| Pieza | Estado |
|---|---|
| Estructura del repo, CI, Pint, PHPStan, Pest | Hecho |
| Contratos del núcleo (`src/Contracts`) | Hecho, congelados |
| Resolvers: namespace, nwidart, InterNACHI + autodetección | Hecho |
| Extracción estática con nikic/php-parser | Hecho |
| Reglas: `internal_access`, `undeclared_dependency`, `cycles` | Hecho |
| Baseline | Hecho |
| Reporters: text, json, github | Hecho |
| Comandos `cordon:verify` y `cordon:modules` | Hecho |
| Guideline de Laravel Boost | Hecho |
| Tests unitarios y de feature con fixtures | Escritos, **pendientes de ejecutar** |
| Nombre final y vendor de Composer | Decidido: **Cordon Modulith**, paquete `chrisabner/cordon-modulith`. Falta verificar marcas (Fase 0) |

**Importante:** el código se escribió sin poder ejecutar PHP. La primera tarea obligatoria es `composer install && composer check` y corregir lo que falle (brief 01).

## Cómo trabajar con enjambres de agentes

1. Cada fase tiene un brief en `enjambres/` con objetivo, archivos permitidos, tareas, tests de aceptación, definición de terminado y un prompt listo para pegar.
2. Los agentes leen primero `AGENTS.md`, `docs/architecture.md` y los proyectos de `referencias.md` marcados para su brief.
3. Un enjambre no toca archivos fuera de su alcance. Tú integras y mergeas.
4. Cualquier cambio a contratos, formato de config, baseline u opciones de comandos requiere un ADR aprobado por ti.
5. Una fase se cierra solo cuando `composer check` pasa en CI y se cumple su definición de terminado.

## Índice

- [roadmap.md](roadmap.md): fases, calendario y criterios de salida.
- [referencias.md](referencias.md): proyectos de referencia y qué estudiar de cada uno por brief.
- [enjambres/00-fundacion.md](enjambres/00-fundacion.md)
- [enjambres/01-verificacion-nucleo.md](enjambres/01-verificacion-nucleo.md)
- [enjambres/02-integraciones.md](enjambres/02-integraciones.md)
- [enjambres/03-docs-e-ia.md](enjambres/03-docs-e-ia.md)
- [enjambres/04-lanzamiento.md](enjambres/04-lanzamiento.md)
- [enjambres/05-documentacion-viva.md](enjambres/05-documentacion-viva.md)
- [enjambres/06-ecosistema.md](enjambres/06-ecosistema.md)
