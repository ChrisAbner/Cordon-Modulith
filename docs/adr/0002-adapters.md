# ADR 0002: Adapt to existing module layouts

**Status:** accepted

## Context
nwidart/laravel-modules and InterNACHI/modular already dominate module organisation in Laravel, and many teams use a plain `app/Modules` folder. None of them enforces boundaries.

## Decision
Cordon Modulith never scaffolds or registers modules. It reads the existing layout through `ModuleResolver` implementations (`namespace`, `nwidart`, `internachi`) selected by config or auto-detected. Module membership of a file is decided by path; of a class, by namespace.

## Consequences
- Cordon Modulith complements those packages instead of competing with them.
- New layouts only need a new resolver.
