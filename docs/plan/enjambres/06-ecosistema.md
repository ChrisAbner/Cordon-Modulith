# Enjambre 06 · Ecosistema y v1.0

**Fase:** 6 · **Duración:** meses 5–6+ · **Dependencias:** tracción de v0.x

**Referencias:** ver los proyectos marcados con el brief 06 en [referencias.md](../referencias.md).

## Objetivo
Crear el foso competitivo: extensiones, UI y estabilidad.

## Sub-enjambres

### 6A · API de reglas propias
- Config `rules` acepta clases que implementen `Cordon\Contracts\Rule`. Documentar cómo escribir una regla. ADR obligatorio.

### 6B · Plugin de Filament
- Paquete aparte: página con el grafo de módulos y la lista de violaciones.

### 6C · v1.0
- Congelar API pública, política de SemVer, guía de actualización desde 0.x.

### 6D · Sostenibilidad
- GitHub Sponsors. Evaluar una capa Pro solo si hay tracción (informes de arquitectura para equipos). Revisar la política de marca de Laravel antes de cualquier uso comercial.

## Definición de terminado
- v1.0.0 publicada con changelog y guía de actualización.
