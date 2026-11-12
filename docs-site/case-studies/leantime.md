# Leantime

[Leantime](https://github.com/Leantime/leantime) is an open source project management system. It is not built on a module package: its 45 modules are plain folders under `app/Domain`, each with its own controllers, services, repositories, models and templates. They cover projects and tickets (`Projects`, `Tickets`, `Sprints`, `Timesheets`), planning boards (`Canvas`, `Goalcanvas`, `Blueprints`, `Ideas`), users and access (`Users`, `Auth`, `Ldap`, `Oidc`) and the app shell (`Menu`, `Setting`, `Notifications`).

**Analysed:** commit [`bbba712`](https://github.com/Leantime/leantime/tree/bbba712e69e43971cb103033ac6048bd073d1e63) (release v3.14.0), November 2026, with Cordon 0.4.0 and PHP 8.4. Cordon does not analyse Blade templates, so the 380 `*.blade.php` files are not part of these numbers.

## Results

| | Default config | Public API declared |
|---|---|---|
| Modules | 45 | 45 |
| Files analysed | 522 | 522 |
| Cross-module references | 766 | 766 |
| `internal_access` | 443 | 166 |
| `cycles` | 144 dependencies in 1 group of 24 modules (plus 1 group of 2) | same |
| Time | 1.2 s | 1.1 s |

We read 20 reports at random by hand. All 20 pointed at a real reference.

## Configuration

Leantime's own layering says how modules should talk: controllers call services, services call repositories. So we declared the services as the public API, plus the `Permissions` classes, which only hold constants.

```php
// config/cordon.php
'resolver' => 'namespace',

'resolvers' => [
    'namespace' => ['path' => 'app/Domain', 'namespace' => 'Leantime\\Domain'],
],

'host' => ['paths' => []],

'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions', 'Services', 'Permissions'],
```

Services were the biggest part of the first report: `Projects\Services\Projects` alone had 52 references and `Tickets\Services\Tickets` 27. Declaring them removed 277 of the 443 reports.

## What remains

Most of the remaining 166 reports skip the service layer and go straight to another module's repository (115) or model (42):

| Class | References | From modules |
|---|---|---|
| `Auth\Models\Roles` | 23 | 16 |
| `Setting\Repositories\Setting` | 20 | 12 |
| `Users\Repositories\Users` | 19 | 13 |
| `Tickets\Repositories\Tickets` | 14 | 11 |
| `Notifications\Models\Notification` | 13 | 9 |

```php
// app/Domain/Auth/Services/Auth.php
public SettingRepository $settingsRepo;

// app/Domain/Connector/Controllers/Providers.php
Auth::authOrRedirect([Roles::$owner, Roles::$admin, Roles::$manager, Roles::$editor]);
```

`Roles` is a set of role constants read by 16 modules, a good candidate for an enum. The repositories are the more interesting part: `Users` and `Setting` both have a service, yet a dozen modules read their repositories directly. Declaring `Repositories` public as well would bring the report down to 89, but it would also hide the fact that the service layer is being bypassed. We left them reported.

Declaring the services surfaced 27 `public_api_leak` warnings: public service methods that take or return internal models, such as `Blueprints\Services\TemplateRegistry::get()` returning a `CanvasTemplate` model.

## Cycles through public services

24 of the 45 modules belong to one group of mutually dependent modules, with 32 direct back-and-forth pairs. Some of those cycles go only through services, which are public here:

```php
// app/Domain/Menu/Services/Menu.php
use Leantime\Domain\Projects\Services\Projects as ProjectService;

// app/Domain/Projects/Hxcontrollers/ProjectCard.php
use Leantime\Domain\Menu\Services\Menu;
```

Every reference is legal, and `Menu` and `Projects` still depend on each other. Declaring a public API removes access reports. It never removes a cycle.

Leantime has the smallest cycle group of the five apps we measured (53% of its modules). Its modules are also the smallest: 12 files each on average, against 32 to 67 in the other four.
