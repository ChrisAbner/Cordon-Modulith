# Enjambre 03 · Documentación e IA

**Fase:** 3 · **Duración:** semanas 7–8 · **Dependencias:** fase 1 (2 recomendable)

**Referencias:** ver los proyectos marcados con el brief 03 en [referencias.md](../referencias.md).

## Objetivo
Que alguien ajeno instale y configure Cordon Modulith en menos de 15 minutos, y que los agentes de IA respeten los límites automáticamente.

## Sub-enjambres

### 3A · Sitio de documentación
- Alcance: `docs-site/` (VitePress o similar).
- Páginas: introducción, instalación, modelo de API pública, configuración, reglas, baseline, CI, recetas ("cómo exponer un servicio", "cómo romper un ciclo con eventos"), FAQ, comparativa honesta con Deptrac, Pest `arch()` y laravel-true-modular.

### 3B · App demo
- Alcance: repo aparte `cordon-demo`.
- Laravel 13 con nwidart, 4 módulos, violaciones intencionales, CI en rojo y rama `fixed` en verde.

### 3C · Skill de Laravel Boost
- Alcance: `resources/boost/skills/cordon-fix-violations/SKILL.md`.
- Enseña al agente a leer la salida de `cordon:verify --format=json` y corregir cada tipo de violación (extraer contrato, publicar evento, declarar dependencia).

### 3D · Guion de vídeo (3 min)
- Alcance: `docs/plan/video.md`. Problema → instalación → violación en CI → corrección → baseline.

## Definición de terminado
- Prueba con una persona ajena: instalación y primera verificación en < 15 min.
- Tú revisas y apruebas el README y la página de inicio.

## Prompt para pegar (3C)
```
Lee AGENTS.md, README.md y docs/plan/enjambres/03-docs-e-ia.md (3C).
Escribe resources/boost/skills/cordon-fix-violations/SKILL.md siguiendo el formato de skills
de Laravel Boost. Debe explicar cómo corregir internal_access, undeclared_dependency y cycles
con ejemplos de código antes/después. Nunca debe recomendar añadir violaciones al baseline.
```
