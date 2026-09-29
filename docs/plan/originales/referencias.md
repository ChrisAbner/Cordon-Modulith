# Proyectos de referencia

Qué estudiar de cada proyecto y en qué brief se usa. Regla para los enjambres: **estudiar, no copiar**. La mayoría son MIT; si se reutiliza código, conservar el aviso de licencia y mencionarlo en el PR. Nunca importar decisiones que choquen con los contratos congelados (`AGENTS.md`).

## 1. Concepto: qué construir

| Proyecto | Lenguaje | Qué estudiar | Brief |
|---|---|---|---|
| [Spring Modulith](https://github.com/spring-projects/spring-modulith) | Java | `ApplicationModules.verify()`, "named interfaces" (equivalente a nuestro `public`), documentación generada (diagramas y canvas por módulo), event publication registry | 02, 05, 06 |
| [Packwerk](https://github.com/Shopify/packwerk) | Ruby | Dependencias por paquete en `package.yml` (nuestro `depends_on`), `package_todo.yml` (nuestro baseline), flujo de adopción en monolitos grandes | 01, 03 |
| [packwerk-extensions](https://github.com/rubyatscale/packwerk-extensions) | Ruby | Por qué la verificación de privacidad acabó separada del núcleo: lecciones sobre fricción y adopción | 03, 04 |
| [ArchUnit](https://github.com/TNG/ArchUnit) | Java | Reglas de arquitectura expresadas como tests; API fluida y mensajes de error | 02A |

## 2. Técnica en PHP: cómo construirlo

| Proyecto | Qué estudiar | Brief |
|---|---|---|
| [Deptrac](https://github.com/deptrac/deptrac) | Uso de nikic/php-parser, "collectors", resolución de referencias, formato de baseline, rendimiento y caché. Es la alternativa que la gente usa hoy a mano: la comparativa debe ser honesta | 01B, 01D, 03A |
| [nikic/PHP-Parser](https://github.com/nikic/PHP-Parser) | Documentación de `NameResolver`, visitors y nodos de v5. Fuente de los casos límite de extracción | 01A, 01B |
| [PHPStan](https://github.com/phpstan/phpstan) / [Larastan](https://github.com/larastan/larastan) | Formato del baseline, calidad de los mensajes de error, infraestructura de reglas y extensiones | 01A, 02B |
| [PHPat](https://github.com/carlosas/phpat) | Tests de arquitectura sobre PHPStan: API de reglas y casos límite | 02A, 02B |
| [PHPArkitect](https://github.com/phparkitect/arkitect) | Reglas de arquitectura con análisis estático, formato de salida y baseline | 01B, 02A |
| [Pest `arch()`](https://pestphp.com/docs/arch-testing) | La opción que viene incluida en Laravel. Documentar su límite (solo ve clases que existen y se pueden cargar): es nuestro diferenciador | 02A, 03A |

## 3. Ecosistema Laravel: dónde encajar

| Proyecto | Qué estudiar | Brief |
|---|---|---|
| [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules) | Estructura de módulos (v11+ con `app/`), `module.json`, config `modules.paths.modules` y `modules.namespace` | 01C |
| [InterNACHI/modular](https://github.com/InterNACHI/modular) | Estructura `app-modules/`, `composer.json` por módulo, config `app-modules` | 01C |
| [happenv-com/laravel-true-modular](https://github.com/happenv-com/laravel-true-modular) | Competidor directo: seguir releases, funcionalidades y posicionamiento | 03A, 04 |
| [Laravel Boost](https://github.com/laravel/boost) | Formato de guidelines y skills para paquetes de terceros | 03C |
| [Laravel Pint](https://github.com/laravel/pint), [Pest](https://github.com/pestphp/pest), [Orchestra Testbench](https://github.com/orchestral/testbench) | Estándar de calidad y testing de paquetes Laravel | 01A |
| Paquetes de [Spatie](https://github.com/spatie) | README, documentación y changelog de referencia para paquetes Laravel | 03A |

## 4. Fuera de PHP: ideas puntuales

| Proyecto | Lenguaje | Qué estudiar | Brief |
|---|---|---|---|
| [import-linter](https://github.com/seddonym/import-linter) | Python | Tipos de contrato claros (independencia, capas, prohibido) y su configuración | 06A |
| [dependency-cruiser](https://github.com/sverweij/dependency-cruiser) | JavaScript | Visualización y reporte de grafos de dependencias | 05A |
| [Nx `enforce-module-boundaries`](https://nx.dev/features/enforce-module-boundaries) | JavaScript/TypeScript | Etiquetas para permitir o prohibir dependencias entre grupos de módulos (idea posterior a 1.0) | 06A |

## Cómo usar este documento con un enjambre

Añade al prompt del brief:

```
Antes de implementar, lee docs/plan/referencias.md y estudia los proyectos marcados
para tu brief. Entrega un resumen de 5 líneas por proyecto: qué hace bien, qué
aplicaríamos a Cordon y qué descartamos porque choca con AGENTS.md o los ADRs.
No copies código; si reutilizas algo, indica la licencia en el PR.
```
