# Filament

The Filament plugin shows the modules of your application inside a [Filament](https://filamentphp.com) panel: a table with their namespace, dependencies, violations and owner, the dependency graph, and the card `cordon:docs` writes for each module.

It is a separate package, `chrisabner/cordon-modulith-filament`, so Cordon Modulith itself keeps no dependency on Filament. It needs Cordon Modulith 0.5 or later, Laravel 12 or 13, and Filament 3.2 or later or Filament 4.

## Installation

```bash
composer require chrisabner/cordon-modulith-filament
```

Register the plugin in your panel provider:

```php
use ChrisAbner\CordonFilament\CordonPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(CordonPlugin::make());
}
```

## The pages

| Page | What it shows |
|---|---|
| Modules | One row per module: namespace, `depends_on`, violations and the `owner` of its `metadata`. Each name opens its card. |
| Module graph | The dependency graph, drawn with Mermaid. Arrows with violations are red, dotted arrows are listeners. |
| Module | The card of `cordon:docs`: diagram, components, public API, what the module uses and what uses it, events and violations. |

The pages read the same analysis as `cordon:verify`. If `config/cordon.php` has errors, or no module is found, they show those problems, with the messages of the command, instead of an empty graph. The table and the graph apply the [baseline](../guide/baseline). The card lists every violation in the code, as `cordon:docs` does.

## Options

```php
CordonPlugin::make()
    ->navigationGroup('Architecture')
    ->authorizeUsing(fn () => auth()->user()?->isAdmin())
    ->mermaidUrl('/vendor/mermaid/mermaid.esm.min.mjs');
```

- `navigationGroup()` puts the navigation items in a group.
- `authorizeUsing()` limits who may open the pages. By default everyone who can open the panel can.
- `mermaidUrl()` is the script that draws the graphs, Mermaid 11 from jsDelivr by default. Serve your own copy if the panel has a content security policy. If the script does not load, the pages show the source of the diagram.

Every page load analyses the project again. On a large codebase set the [`cache`](../guide/configuration#cache) key, so only the files that changed are parsed.

## For plugin authors

The plugin only uses the [public API](../guide/compatibility): `Cordon\Testing\Cordon` for the modules, the configuration errors and the violations, and `Cordon\Laravel\Architecture` for the graph and the module documentation as text.

```php
use Cordon\Laravel\Architecture;

Architecture::graph();             // the Mermaid flowchart of cordon:graph
Architecture::graph('Billing');    // only Billing and its neighbours
Architecture::docs();              // ['README.md' => ..., 'modules/Billing.md' => ..., 'events.md' => ...]
```

Check `Cordon::configurationErrors()` before you call them: they describe the configuration as it is.
