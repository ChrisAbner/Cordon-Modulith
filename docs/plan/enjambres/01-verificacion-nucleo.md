# Enjambre 01 · Verificación y endurecimiento del núcleo

**Fase:** 1 · **Duración:** semanas 2–5 · **Dependencias:** brief 00 (repo con CI)

**Referencias:** ver los proyectos marcados con el brief 01 en [referencias.md](../referencias.md).

## Objetivo
Llevar el núcleo del MVP a calidad de release: todos los tests en verde, sin falsos positivos en proyectos reales y con buen rendimiento.

## Contexto obligatorio
`AGENTS.md`, `docs/architecture.md`, `docs/adr/*`. El código se escribió sin ejecutar PHP: esperar errores menores.

## Sub-enjambres (paralelos)

### 1A · Verde en CI
- Alcance: `src/`, `tests/`, `phpstan.neon.dist`.
- Ejecutar `composer install && composer check`, corregir fallos de Pest, PHPStan (nivel 8) y Pint sin cambiar contratos.
- Hecho cuando: la matriz completa de CI pasa.

### 1B · Casos límite de extracción
- Alcance: `src/Analysis/ReferenceCollector.php`, `src/Analysis/PhpParserExtractor.php`, `tests/Unit/ExtractorTest.php`, `tests/Fixtures/edge-cases/`.
- Cubrir con fixtures: enums, traits, clases anónimas, first-class callables (`Foo::bar(...)`), `match`, atributos con argumentos de clase, `instanceof`, `catch` múltiple, tipos unión/intersección/DNF, constantes de clase (`Foo::class`), closures estáticas, promoted properties.
- Hecho cuando: cada caso tiene test y ningún falso positivo.

### 1C · Fixtures de proyectos reales
- Alcance: `tests/Fixtures/real-*`, `tests/Unit/RealProjectsTest.php`.
- Recrear 5–10 estructuras reales (nwidart v11+ con `app/`, InterNACHI, `app/Modules`, DDD con `src/Domain`), anonimizadas. Documentar violaciones esperadas.
- Hecho cuando: los resultados coinciden con lo esperado revisado por ti.

### 1D · Rendimiento
- Alcance: `tests/Benchmark/`, optimizaciones en `src/Analysis/` sin cambiar contratos.
- Generar un proyecto sintético de 1.000 y 5.000 archivos; medir. Si hace falta, caché por hash de archivo (proponer ADR antes).
- Hecho cuando: 1.000 archivos < 10 s en CI.

## Tests de aceptación de la fase
- `composer check` verde en PHP 8.3/8.4 × Laravel 12/13.
- `php artisan cordon:verify` probado a mano en 3 proyectos reales (uno por resolver).

## Prompt para pegar (1A)
```
Lee AGENTS.md, docs/architecture.md y docs/plan/enjambres/01-verificacion-nucleo.md (sección 1A).
Ejecuta `composer install && composer check`. Corrige los fallos en src/ y tests/ sin cambiar
las firmas de src/Contracts ni los value objects. Si un test está mal y no el código, explica por qué
antes de cambiarlo. Entrega: lista de cambios y salida final de composer check.
```
