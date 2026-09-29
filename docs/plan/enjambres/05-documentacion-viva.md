# Enjambre 05 · Documentación viva

**Fase:** 5 · **Duración:** meses 3–4 · **Dependencias:** v0.1 publicada

**Referencias:** ver los proyectos marcados con el brief 05 en [referencias.md](../referencias.md).

## Objetivo
Que Cordon Modulith documente la arquitectura real a partir del código, como Spring Modulith.

## Sub-enjambres

### 5A · Diagramas
- Comando `cordon:docs` que genera en `docs/modules/`: un diagrama Mermaid global de dependencias entre módulos y uno por módulo.
- Requiere ADR: nuevo comando y formato de salida.

### 5B · Canvas de módulo
- Un Markdown por módulo: namespace, API pública (clases), dependencias entrantes y salientes, violaciones actuales.

### 5C · Inventario de eventos
- Detectar clases de eventos públicos, dónde se despachan (`event(new X)`, `X::dispatch()`) y quién escucha (tipos de `handle()` en listeners). Tabla publicador → evento → oyentes.

## Definición de terminado
- `cordon:docs` probado en la app demo y en 2 proyectos reales.
- Documentado en el sitio.
