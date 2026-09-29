# Roadmap

Objetivo: ser la forma recomendada de verificar límites entre módulos en Laravel, como complemento de nwidart, InterNACHI o una carpeta `app/Modules`, nunca como reemplazo.

## Definición de MVP desplegable (v0.1.0 en Packagist)

- `composer check` en verde en la matriz PHP 8.3/8.4 × Laravel 12/13.
- Probado a mano en al menos 3 proyectos reales (uno por tipo de resolver) sin falsos positivos.
- Nombre y vendor definitivos, README revisado por ti.
- Tag `v0.1.0` y paquete publicado en Packagist.

Fases 0, 1 y la parte mínima de 3 bastan para el MVP. El resto construye adopción.

## Fases

| Fase | Semanas | Estado | Criterio de salida |
|---|---|---|---|
| 0. Fundación | 1 | Parcial: nombre y vendor decididos, falta verificar marcas | Nombre verificado, repo en GitHub, CI ejecutándose |
| 1. Núcleo | 2–5 | Implementado, falta verificar | Tests en verde, 5+ fixtures reales, 0 falsos positivos, 1.000 archivos en < 10 s |
| 2. Integraciones | 6–7 | Pendiente | Expectativa Pest, regla PHPStan, GitHub Action reutilizable |
| 3. Docs e IA | 7–8 | Parcial: README y guideline Boost | Sitio de docs, app demo, skill de Boost, guion de vídeo |
| 4. Lanzamiento v0.1 | 9–10 | Pendiente | Artículo en Laravel News enviado, posts publicados, contacto con mantenedores |
| 5. Documentación viva | Meses 3–4 | Pendiente | `cordon:docs` genera diagramas Mermaid e inventario de eventos |
| 6. Ecosistema y v1.0 | Meses 5–6+ | Pendiente | Plugin Filament, API de reglas propias, v1.0 con SemVer estricto |

## Métricas objetivo (escenario realista)

- Mes 3: 5.000 instalaciones, 150 estrellas.
- Mes 6: 15.000 instalaciones, 400 estrellas.
- Mes 12: 15.000–40.000 instalaciones.

## Riesgos vigilados

- happenv-com/laravel-true-modular madura rápido: diferenciarse con adaptadores múltiples y baseline.
- nwidart o InterNACHI añaden verificación nativa: proponer colaboración temprana.
- Nombre: verificar disponibilidad y marcas de "Cordon Modulith" antes de publicar (Fase 0).
