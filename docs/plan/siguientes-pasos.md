# Siguientes pasos

Lo que queda para publicar la v0.1.0 de Cordon Modulith y lanzarla. Todo lo que se podía hacer desde el código ya está hecho (ver [README.md](README.md)). Estos pasos los decides o los ejecutas tú, en este orden.

## 1. Integrar la rama en `main`

La rama `claude/cordon-modulith-naming-1w2srv` tiene todo el trabajo. El CI solo corre en `main` y en pull requests, así que todavía no se ha ejecutado en GitHub.

- [ ] Abrir un pull request de la rama a `main`.
- [ ] Comprobar que pasan los tres jobs de `tests.yml`:
  - `tests`: PHP 8.3/8.4 × Laravel 12/13. **PHP 8.3 no se ha probado localmente**, solo con PHPStan configurado para 8.3.
  - `quality`: Pint y PHPStan.
  - `benchmark`: 1.000 archivos en menos de 10 s.
- [ ] Mergear.

## 2. Decidir los ADR propuestos

AGENTS.md exige un ADR aceptado para cambiar comandos, configuración o API pública. Estos se implementaron con estado `proposed`:

| ADR | Decisión | Si lo rechazas |
|---|---|---|
| [0005](../adr/0005-module-option.md) | Opción `--module` y servicio `Verifier` | Quitar la opción de `VerifyCommand` |
| [0006](../adr/0006-pest-expectation.md) | Expectativa de Pest `toRespectBoundaries()` | Quitar `src/Testing` y el `files` de `composer.json` |
| [0007](../adr/0007-phpstan-rule.md) | Regla de PHPStan y config fuera de Laravel | Quitar `src/PHPStan`, `extension.neon` y `extra.phpstan` |
| [0008](../adr/0008-living-documentation.md) | Comando `cordon:docs` | Quitar `src/Documentation` y `DocsCommand` |
| [0009](../adr/0009-custom-rules.md) | Reglas propias en `rules` | Volver al `RuleSet` anterior |

- [ ] Cambiar cada uno a `**Status:** accepted` o pedir que se revierta.

## 3. Cerrar la Fase 0 (nombre y repositorio)

- [ ] Verificar la marca "Cordon Modulith": USPTO y EUIPO, clases 9 y 42. El prompt de investigación está en [enjambres/00-fundacion.md](enjambres/00-fundacion.md).
- [x] Packagist: a 29/09/2026 no existe ningún paquete `cordon-modulith`. El más cercano es `cordon/account-review`, un bundle de Symfony sin relación.
- [ ] Decidir si reservas un dominio (por ejemplo `cordonmodulith.dev`).
- [ ] Hacer público el repositorio `ChrisAbner/Cordon-Modulith`.
- [ ] Activar GitHub Pages (Settings → Pages → Source: **GitHub Actions**). Si no se activa, el workflow `docs.yml` falla al hacer push a `main`.
- [ ] Activar GitHub Discussions y publicar el RFC ([lanzamiento/01-rfc-discussions.md](lanzamiento/01-rfc-discussions.md)).

## 4. Probar a mano en 3 proyectos reales

Es el criterio de salida de la Fase 1: uno por resolver (nwidart, InterNACHI y `app/Modules`), sin falsos positivos. Antes de publicar en Packagist puedes instalarlo desde tu copia local:

```bash
# En el proyecto Laravel a probar
composer config repositories.cordon path ../Cordon-Modulith
composer require --dev chrisabner/cordon-modulith:@dev

php artisan cordon:modules            # ¿detectó bien los módulos?
php artisan cordon:verify             # ¿todas las violaciones son reales?
php artisan cordon:verify --format=json > cordon-report.json
php artisan cordon:docs               # revisar docs/architecture/
vendor/bin/phpstan analyse            # si el proyecto usa PHPStan
```

- [ ] Proyecto nwidart.
- [ ] Proyecto InterNACHI.
- [ ] Proyecto `app/Modules` o DDD.
- [ ] Cada falso positivo se convierte en un fixture en `tests/Fixtures/real-*` con su `expected.json` y en un test que falle antes de arreglarlo.
- [ ] Anotar el tiempo de `cordon:verify` en el proyecto más grande.

## 5. App demo y prueba con una persona ajena (brief 03)

- [ ] Crear el repo `cordon-demo`: Laravel 13 con nwidart, 4 módulos, violaciones intencionales, CI en rojo en `main` y una rama `fixed` en verde. Puede partir de `tests/Fixtures/real-nwidart-shop`.
- [ ] Pedir a alguien que no conozca el proyecto que lo instale siguiendo solo la documentación. Objetivo: primera verificación en menos de 15 minutos. Anotar dónde se atasca.
- [ ] Revisar y aprobar el README y la portada del sitio (`docs-site/index.md`).
- [ ] Grabar el vídeo ([video.md](video.md)) y el GIF para el README.

## 6. Publicar la v0.1.0

- [ ] En `CHANGELOG.md`, cambiar `## [Unreleased] - 0.1.0` por `## [0.1.0] - AAAA-MM-DD`.
- [ ] Crear y subir el tag:

  ```bash
  git tag v0.1.0
  git push origin v0.1.0
  ```

- [ ] Crear el GitHub Release con las notas del changelog. La GitHub Action se referencia como `ChrisAbner/Cordon-Modulith@v0.1.0`, así que el tag tiene que existir.
- [ ] Registrar el paquete en [packagist.org](https://packagist.org/packages/submit) con la URL del repo y activar el webhook de GitHub para actualizaciones automáticas.
- [ ] Comprobar en un proyecto limpio: `composer require --dev chrisabner/cordon-modulith`.
- [ ] Opcional: publicar la acción en GitHub Marketplace.

## 7. Lanzamiento (brief 04)

Los borradores y el calendario están en [lanzamiento/README.md](lanzamiento/README.md). Antes de publicar cada uno:

- [ ] Sustituir los enlaces "(link)".
- [ ] Comprobar que la salida de ejemplo coincide con la versión publicada.
- [ ] Ningún texto afirma afiliación con Laravel.

Orden sugerido: RFC → Laravel News, r/laravel y X el día del lanzamiento → dev.to (+2 días) → mantenedores de nwidart e InterNACHI (+1 semana) → CFP según calendario.

## 8. Después de la v0.1 (Fase 6)

- [ ] **6B · Plugin de Filament:** paquete aparte con el grafo de módulos y la lista de violaciones. Puede reutilizar `Analyzer::snapshot()` y `DocumentationGenerator`.
- [ ] **6C · v1.0:** congelar la API pública (incluidos `Rule` y `AnalysisContext` por el ADR 0009), escribir la política de SemVer y la guía de actualización desde 0.x.
- [ ] **6D · Sostenibilidad:** GitHub Sponsors. Revisar la política de marca de Laravel antes de cualquier uso comercial.
- [ ] Mejoras candidatas surgidas en esta fase: caché por hash de archivo si algún proyecto real supera los 10 s, diagramas C4, y aplicar el baseline de Cordon también en la regla de PHPStan.
