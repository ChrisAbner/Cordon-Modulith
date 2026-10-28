# Advising App

[Advising App](https://github.com/canyongbs/advisingapp) by Canyon GBS is a student advising and CRM platform for colleges, built with Laravel and Filament. It is organised with [InterNACHI/modular](https://github.com/InterNACHI/modular): 37 modules under `app-modules/`, from `prospect` and `student-data-model` to `ai`, `workflow` and several integrations.

**Analysed:** commit [`0ced55f`](https://github.com/canyongbs/advisingapp/tree/0ced55f96e13d65ba21a3845fb2736522fdbca3b), October 2026, Laravel 13.39, InterNACHI/modular 3.0.2, PHP 8.4. Cordon detected the modules on its own; no resolver configuration was needed.

## Results

| | Default config | Public API declared |
|---|---|---|
| Modules | 37 | 37 |
| Files analysed | 2,463 | 2,463 |
| Cross-module references | 2,774 | 2,774 |
| `internal_access` | 1,162 | 1,154 |
| `cycles` | 1 group of 29 modules | 1 group of 29 modules |
| Time / memory | 3.8 s / 152 MB | 4.5 s / 152 MB |

The only addition was `DataTransferObjects` as a public namespace (8 reports). Unlike the other two apps, Advising App has no shared kernel, and the default public namespaces already match how it is organised: its contracts live in `Contracts` folders (14 of them, in 8 modules), which Cordon treats as public at any depth.

```php
'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions', 'DataTransferObjects'],
```

## Two models carry the product

28% of the reports point at just two classes:

| Class | References | From modules |
|---|---|---|
| `StudentDataModel\Models\Student` | 162 | 21 |
| `Prospect\Models\Prospect` | 162 | 20 |
| `Audit\Models\Concerns\Auditable` | 61 | 16 |
| `Team\Models\Department` | 23 | 7 |

Students and prospects are what the product is about, so it is no surprise that reports, engagements, forms, alerts and workflows all reach for them. What is interesting is that the app already has the abstraction: `StudentDataModel\Models\Contracts\Educatable`, implemented by both models and public under Cordon's defaults. Much of the code still names the concrete classes:

```php
// app-modules/student-data-model/src/Actions/ResolveEducatableFromEmail.php
public function __invoke(?string $email, bool $includingArchived = false): Student | Prospect | null

// app-modules/engagement/src/Filament/Pages/ViewEngagementResponse.php
if (($this->record->sender instanceof Prospect || $this->record->sender instanceof Student) && ...
```

Cordon gives the team a list of exactly where to move from `Student | Prospect` to `Educatable`, module by module.

`Auditable` is a trait that 16 modules use on their models. That is a deliberate shared mechanism, and declaring it (`'audit' => ['public' => ['Models\\Concerns\\Auditable']]`) would be a one-line decision.

## Cycles

29 of the 37 modules belong to one group of mutually dependent modules, with 54 direct back-and-forth pairs, for example `ai` and `assistant`. The heaviest dependencies all go towards `student-data-model` and `prospect`: `report` (51 references), `engagement` (41) and `form` (36 and 39). Those two modules also depend back on most of their users: `student-data-model` on 16 of them and `prospect` on 15, from `engagement` and `task` to `timeline` and `notification`. That is what ties the group together. A module everyone depends on stays stable only if it depends on almost nothing itself.
