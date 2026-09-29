# Enjambre 02 · Integraciones de desarrollo

**Fase:** 2 · **Duración:** semanas 6–7 · **Dependencias:** fase 1 cerrada

**Referencias:** ver los proyectos marcados con el brief 02 en [referencias.md](../referencias.md).

## Objetivo
Que Cordon Modulith se use donde el equipo ya trabaja: tests de Pest, PHPStan y GitHub Actions.

## Sub-enjambres (paralelos)

### 2A · Expectativa Pest
- Alcance: `src/Testing/`, `tests/Unit/PestExpectationTest.php`.
- API: `expect('Billing')->toRespectBoundaries();` y `expect(Cordon::modules())->each->toRespectBoundaries();`
- Reutiliza `Analyzer` y filtra violaciones por módulo origen. Requiere ADR para cualquier cambio en `Result`.

### 2B · Regla PHPStan
- Alcance: `src/PHPStan/`, `extension.neon`, `tests/PHPStan/`.
- Regla que reporta `internal_access` en el editor. Leer config desde un archivo `cordon.php` independiente de Laravel (ADR necesario: config fuera de Laravel).

### 2C · GitHub Action reutilizable
- Alcance: `action.yml` (acción compuesta), `docs/ci.md`.
- Entradas: `php-version`, `working-directory`, `format`. Documentar uso en 5 líneas.

### 2D · Opción `--module`
- Alcance: `src/Laravel/Commands/VerifyCommand.php`, tests de feature.
- `cordon:verify --module=Billing` limita el reporte a un módulo origen.

## Definición de terminado
- Cada integración con tests y documentación en README.
- App demo (brief 03) usando las tres integraciones.

## Prompt para pegar (2A)
```
Lee AGENTS.md, docs/architecture.md y docs/plan/enjambres/02-integraciones.md (2A).
Implementa una expectativa de Pest `toRespectBoundaries()` en src/Testing/ que reutilice
Cordon\Analysis\Analyzer. Escribe primero los tests usando tests/Fixtures/namespace-app.
No cambies src/Contracts. Si necesitas cambiar Result, detente y redacta un ADR en docs/adr/.
```
