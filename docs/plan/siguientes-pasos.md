# Siguientes pasos

Lo que queda para publicar la v0.1.0 de Cordon Modulith y lanzarla. Todo lo que se podía hacer desde el código ya está hecho (ver [README.md](README.md)). Estos pasos los decides o los ejecutas tú, en este orden. Los textos y los clics exactos para GitHub y Packagist están en [publicacion/](publicacion/README.md).

## 1. Integrar la rama en `main`

La rama `claude/cordon-modulith-naming-1w2srv` tiene todo el trabajo. El CI solo corre en `main` y en pull requests, así que todavía no se ha ejecutado en GitHub.

- [x] Reproducir la matriz en local (29/09/2026): PHP 8.3/8.4 × Laravel 12/13 en verde, Pint y PHPStan en verde, benchmark de 1.000 archivos en 5,3 s con Xdebug apagado.
- [ ] Abrir un pull request de la rama a `main`. Título y cuerpo en [publicacion/pr-main.md](publicacion/pr-main.md).
- [ ] Comprobar que pasan los tres jobs de `tests.yml` en GitHub (`tests`, `quality`, `benchmark`).
- [ ] Mergear.

Si ejecutas las herramientas en Windows: Pint falla con `core.autocrlf=true` porque deja CRLF en el árbol de trabajo. El `.gitattributes` ya fuerza `eol=lf`; tras hacer commit, `git rm --cached -r . && git reset --hard` renormaliza tu copia. Ejecuta PHP con `-d xdebug.mode=off`: Xdebug en modo `develop` hace el análisis 3–4 veces más lento.

## 2. Decidir los ADR propuestos

- [x] ADR 0005–0009 aceptados el 29/09/2026 (`--module`, Pest, PHPStan, `cordon:docs`, reglas propias).

## 3. Cerrar la Fase 0 (nombre y repositorio)

- [ ] Verificar la marca "Cordon Modulith" a mano: las bases no se pueden consultar de forma automática. No se encontró ningún bloqueo, pero eso no descarta nada (veredicto: amarillo). Buscar `cordon`, `cordon modulith` y `modulith`, marcas vivas, clases 9 y 42:
  - USPTO: <https://tmsearch.uspto.gov>
  - EUIPO / TMview: <https://www.tmdn.org/tmview/>
  - WIPO: <https://branddb.wipo.int/>
- [x] Añadir al README un aviso de no afiliación con Spring / VMware / Broadcom ("Modulith" viene de Spring Modulith, aunque es un término genérico). Usar siempre "Cordon Modulith" completo, nunca "Modulith" solo.
- [ ] Revisar competidores de nicho en Packagist con "modulith" en el nombre: `modulith/arch-check`, `modulith-php/enforcer`, `kalinkocode/laravel-modulith`.
- [x] Packagist: a 29/09/2026 `chrisabner/cordon-modulith` sigue libre. El más cercano es `cordon/account-review`, un bundle de Symfony sin relación.
- [ ] Decidir si reservas dominio. A 29/09/2026 están libres `cordonmodulith.dev`, `cordonmodulith.com` y `cordon-modulith.dev`; `.io` no se pudo comprobar.
- [ ] Hacer público el repositorio `ChrisAbner/Cordon-Modulith`.
- [ ] Activar GitHub Pages (Settings → Pages → Source: **GitHub Actions**). Si no se activa, el workflow `docs.yml` falla al hacer push a `main`.
- [ ] Activar GitHub Discussions y publicar el RFC ([lanzamiento/01-rfc-discussions.md](lanzamiento/01-rfc-discussions.md)).

## 4. Probar en proyectos reales

Criterio de salida de la Fase 1: uno por resolver, sin falsos positivos. Hecho el 29/09/2026 con 6 proyectos públicos, ejecutando el núcleo con `StandaloneConfig` (sin instalar los proyectos):

| Resolver | Proyecto | Módulos | Archivos | Violaciones | Falsos positivos |
|---|---|---|---|---|---|
| nwidart | [openclassify](https://github.com/openclassify/openclassify) | 18 | 295 | 153 | 0 |
| nwidart | [ShopSmith](https://github.com/morpheusadam/ShopSmith) | 38 | 939 | 613 | 0 |
| InterNACHI | [recruit-party-quest](https://github.com/3pontos-tech/recruit-party-quest) | 17 | 584 | 363 | 0 |
| InterNACHI | [100DiasDeCodigo](https://github.com/he4rt/100DiasDeCodigo) | 5 | 70 | 15 | 0 |
| namespace | [monica](https://github.com/monicahq/monica) (`app/Domains`) | 3–27 | 582 | 57–124 | 0 |
| namespace | [modular-monolith-laravel](https://github.com/avosalmon/modular-monolith-laravel) | 4 | 65 | 2 | 0 |

- [x] Proyectos nwidart, InterNACHI y `app/Modules`/DDD.
- [x] Bug encontrado y corregido con fixtures (`nwidart-lowercase`, `nwidart-unloadable`): fuera de Laravel, un `config/modules.php` con `base_path()` no se cargaba y se usaba `Modules` en silencio. Ahora se resuelve y, si un config sigue sin poder evaluarse, la regla de PHPStan lo avisa (`cordon.configuration`).
- [x] Bug de Windows corregido: `Module::ownsPath()` con separadores mezclados.
- [x] Tiempo en el proyecto más grande (939 archivos): 4–6 s en caliente en Windows. La primera pasada en frío puede tardar más de 30 s por la lectura inicial de archivos (probablemente Defender) y por Xdebug. `cordon:verify` y `cordon:docs` avisan ahora por stderr cuando Xdebug está activo.
- [ ] Opcional: probarlo tú instalado con `composer require` en un proyecto tuyo con Laravel 12/13 y PHPStan.

## 5. App demo y prueba con una persona ajena (brief 03)

- [x] Demo creada en local en `C:\wamp64\www\cordon-demo`: Laravel 13 con nwidart, 4 módulos (Catalog, Orders, Billing, Shared), `main` con 4 violaciones (una o más por regla) y rama `fixed` en verde. Solo git local, sin remoto.
- [ ] Crear el repo `ChrisAbner/cordon-demo` en GitHub y subir `main` y `fixed`. Tras publicar la v0.1.0, cambiar en ambas ramas el repositorio path por `chrisabner/cordon-modulith:^0.1` (paso descrito en su README) para que el CI funcione: rojo en `main`, verde en `fixed`.
- [x] Prueba simulada de un recién llegado: 3 minutos de lectura y comandos hasta la primera verificación (más 21 de descargas de Composer). Sus fricciones (Pest sin `tests/Pest.php`, `phpstan.neon` completo, ejemplo de `cordon:modules`) ya están corregidas en la documentación.
- [ ] Pedir a una persona real que lo instale siguiendo solo la documentación. Objetivo: primera verificación en menos de 15 minutos.
- [ ] Revisar y aprobar el README y la portada del sitio (`docs-site/index.md`).
- [ ] Grabar el vídeo ([video.md](video.md)) y el GIF para el README. La demo sirve de base.

## 6. Publicar la v0.1.0

- [ ] En `CHANGELOG.md`, cambiar `## [Unreleased] - 0.1.0` por `## [0.1.0] - AAAA-MM-DD`.
- [ ] Crear y subir el tag:

  ```bash
  git tag v0.1.0
  git push origin v0.1.0
  ```

- [ ] Crear el GitHub Release con [publicacion/release-v0.1.0.md](publicacion/release-v0.1.0.md). La GitHub Action se referencia como `ChrisAbner/Cordon-Modulith@v0.1.0`, así que el tag tiene que existir. (`action.yml` ya no está en `export-ignore`: GitHub descarga las acciones como archivo exportado y sin él la acción fallaría.)
- [ ] Registrar el paquete en [packagist.org](https://packagist.org/packages/submit) con la URL del repo y activar el webhook de GitHub para actualizaciones automáticas.
- [ ] Comprobar en un proyecto limpio: `composer require --dev chrisabner/cordon-modulith`.
- [ ] Opcional: publicar la acción en GitHub Marketplace.

## 7. Lanzamiento (brief 04)

Los borradores y el calendario están en [lanzamiento/README.md](lanzamiento/README.md).

- [x] Sustituir los enlaces "(link)" por las URL del repo, del sitio de docs y de Packagist.
- [x] Comprobar que la salida de ejemplo coincide con la real (verificado contra los fixtures el 29/09/2026). Repetirlo si cambia algo antes de publicar.
- [x] Ningún texto afirma afiliación con Laravel.

Orden sugerido: RFC → Laravel News, r/laravel y X el día del lanzamiento → dev.to (+2 días) → mantenedores de nwidart e InterNACHI (+1 semana) → CFP según calendario.

## 8. Después de la v0.1 (Fase 6)

- [ ] **6B · Plugin de Filament:** paquete aparte con el grafo de módulos y la lista de violaciones. Puede reutilizar `Analyzer::snapshot()` y `DocumentationGenerator`.
- [ ] **6C · v1.0:** congelar la API pública (incluidos `Rule` y `AnalysisContext` por el ADR 0009), escribir la política de SemVer y la guía de actualización desde 0.x.
- [ ] **6D · Sostenibilidad:** GitHub Sponsors. Revisar la política de marca de Laravel antes de cualquier uso comercial.
- [ ] Mejoras candidatas: caché por hash de archivo, diagramas C4, y aplicar el baseline de Cordon también en la regla de PHPStan.
- [ ] Surgidas de las pruebas reales (29/09/2026):
  - **ADR candidato: `public_namespaces` en cualquier nivel.** Hoy solo cuenta el primer segmento, así que `Requisitions\Enums\X` y `Requisitions\Events\Y` son internos; fueron ~20 % de las violaciones en una app InterNACHI. Ya está documentado.
  - **Mensaje de ciclos:** lista todo el componente conexo (hasta 30 módulos) aunque el camino mostrado tenga 2–3. Ojo con la clave del baseline si se cambia.
  - **Ciclos formados solo por eventos públicos:** hoy cuentan como ciclo (correcto), pero el mensaje sugiere "usa un evento". Ajustar el texto para ese caso.
  - **`FileCollector` con `scandir`:** ahorra 0,1–1 s en Windows, con la misma lista de archivos. Parche medido en el scratchpad de la sesión, no aplicado.
  - **Aviso de configuración duplicado:** con PHPStan en paralelo puede salir una vez por worker.
