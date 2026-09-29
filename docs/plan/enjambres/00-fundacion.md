# Enjambre 00 · Fundación

**Fase:** 0 · **Duración:** 1 semana · **Dependencias:** ninguna

**Referencias:** ver los proyectos marcados con el brief 00 en [referencias.md](../referencias.md).

## Objetivo
Dejar el proyecto listo para publicar: nombre verificado, vendor definido, repo en GitHub con CI ejecutándose.

## Alcance (archivos permitidos)
`composer.json` (solo `name`), `README.md` (solo placeholders de nombre/vendor), `LICENSE.md`, `.github/`, `docs/plan/`.

## Tareas
1. **Investigación de nombre** (agente de investigación): buscar "Cordon Modulith" en Packagist, GitHub, npm, dominios (cordon.dev, cordonphp.dev, getcordon.dev), USPTO y EUIPO en clase 9/42. Entregar tabla de conflictos. Plan B: "Bulwark".
2. **Tú:** decidir nombre y vendor, crear la organización en GitHub y reservar el dominio.
3. ~~Reemplazar el placeholder de vendor por el real en `composer.json` y `README.md`.~~ Hecho: `chrisabner/cordon-modulith`.
4. Subir el repo, activar GitHub Actions y comprobar que la matriz arranca.
5. Activar GitHub Discussions y publicar el RFC (texto en el brief 04).

## Definición de terminado
- Tabla de conflictos de nombre revisada por ti.
- Repo público, CI ejecutándose (aunque falle: eso lo arregla el brief 01).
- Ningún placeholder de vendor restante en `composer.json` ni `README.md`.

## Prompt para pegar
```
Eres un agente de investigación. Lee docs/plan/enjambres/00-fundacion.md.
Tarea 1: investiga la disponibilidad del nombre "Cordon Modulith" para una librería PHP/Laravel
(Packagist, GitHub, npm, dominios .dev/.io/.com, USPTO y EUIPO clases 9 y 42).
Entrega una tabla: recurso | disponible sí/no | conflicto | riesgo (bajo/medio/alto).
No modifiques archivos del repo.
```
