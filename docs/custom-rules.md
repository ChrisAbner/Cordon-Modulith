# Custom rules

Cordon Modulith ships three rules (`internal_access`, `undeclared_dependency`, `cycles`). You can add your own architecture rules that run with them in `cordon:verify`, the Pest expectation and the baseline.

## 1. Write the rule

A rule implements `Cordon\Contracts\Rule`: a stable `id()` and a `check()` method that yields `Cordon\Analysis\Violation`s.

```php
namespace App\Architecture;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * Only the Shared module may be used by every other module.
 */
final class OnlySharedIsEverywhere implements Rule
{
    public function id(): string
    {
        return 'only_shared_everywhere';
    }

    public function check(AnalysisContext $context): iterable
    {
        $users = [];

        foreach ($context->edges as $edge) {
            $users[$edge->to->name][$edge->from->name] = true;
        }

        $others = count($context->modules) - 1;

        foreach ($users as $module => $from) {
            if ($module !== 'Shared' && count($from) === $others) {
                yield new Violation(
                    $this->id(),
                    sprintf('Every module depends on [%s]. Move what they share to the Shared module.', $module),
                    targetModule: $module,
                    target: $module,
                );
            }
        }
    }
}
```

## 2. Register it

List the class in `config/cordon.php`. Built-in rules keep their `true`/`false` switches:

```php
'rules' => [
    'internal_access' => true,
    'undeclared_dependency' => true,
    'cycles' => true,
    App\Architecture\OnlySharedIsEverywhere::class,
],
```

Rules are built through the Laravel container, so their constructors can receive dependencies. The id must not clash with another rule.

## What a rule receives

`AnalysisContext` is read-only:

| Property | Content |
|---|---|
| `modules` | `ModuleMap` with every module (`name`, `namespace`, `path`, `dependsOn`, `publicApi`, `open`) |
| `edges` | Every reference that crosses a module boundary: `from` and `to` modules and the `reference` (`sourceFile`, `sourceClass`, `target` class, `line`) |
| `symbols` | `SymbolTable`: every class declared in the modules (`get($fqcn)` returns its file, line and `#[PublicApi]`/`#[Internal]` marker) |
| `policy` | `PublicApiPolicy`: `isPublic($fqcn, $module, $declaration)` |

References inside a module are not edges. Code is never loaded: rules work on the static analysis only.

## Writing good violations

- One sentence stating the problem, one stating the fix (the same style as the built-in rules).
- Fill `file` and `line` when the violation points to code, so the GitHub annotations and the baseline work. The baseline keys entries by rule, file and `target`, so choose a `target` that stays stable when unrelated code changes.
- Set `sourceModule` to the module that must change: `cordon:verify --module` and the Pest expectation filter by it.
- Report nothing when unsure. False positives make teams switch the rule off.

## Testing a rule

Build a small fixture with a module layout and run the analyzer on it:

```php
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

it('reports modules everybody depends on', function () {
    $modules = (new NamespaceResolver(__DIR__.'/fixtures/app/Modules', 'App\\Modules'))->resolve();
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig([
        'internal_access' => false,
        'undeclared_dependency' => false,
        'cycles' => false,
        OnlySharedIsEverywhere::class,
    ]));

    expect($analyzer->analyze($modules)->violations)->toHaveCount(1);
});
```
