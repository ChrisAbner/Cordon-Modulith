<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\RuleSet;
use Cordon\Rules\Severity;
use Cordon\Tests\Fixtures\Rules\DeclareDependenciesRule;
use Cordon\Tests\Fixtures\Rules\WarnSeverityRule;

mutates(RuleSet::class);

// A notice in the rule set, such as reading a key an entry does not have, fails the test.
beforeEach(function () {
    set_error_handler(static function (int $level, string $message, string $path, int $line): never {
        throw new ErrorException($message, 0, $level, $path, $line);
    });
});

afterEach(fn () => restore_error_handler());

/**
 * A context over the modules named in $dependencies, with one edge for each [from, to, kind].
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $dependencies
 * @param  array<string, array<string, mixed>>  $config  Per-module settings.
 */
function rule_set_context(array $dependencies, array $config = []): AnalysisContext
{
    $modules = [];

    foreach (['A', 'B', '5'] as $name) {
        $modules[$name] = Module::make($name, 'App\\'.$name, '/p/'.$name)->withConfig($config[$name] ?? []);
    }

    $edges = array_map(
        fn (array $dependency) => new Edge($modules[$dependency[0]], $modules[$dependency[1]], new Reference('/p/'.$dependency[0].'/X.php', null, 'App\\'.$dependency[1].'\\Events\\Y', 1, $dependency[2])),
        $dependencies,
    );

    return new AnalysisContext(new ModuleMap(array_values($modules)), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);
}

/**
 * What the built-in rules of a config report on a context, by rule id.
 *
 * @param  array<array-key, mixed>  $listeners
 * @param  array<array-key, mixed>  $layers
 * @return array<string, int>
 */
function rule_set_counts(AnalysisContext $context, array $listeners = [], array $layers = []): array
{
    $counts = [];

    foreach (RuleSet::fromConfig([], null, $listeners, $layers) as $rule) {
        $counts[$rule->id()] = count(iterator_to_array($rule->check($context), false));
    }

    return $counts;
}

it('counts listeners for depends_on and layers, but not for cycles, unless told otherwise', function () {
    // B uses A, and A listens to an event of B; A is in a layer above B.
    $context = rule_set_context([['B', 'A', Reference::USE], ['A', 'B', Reference::LISTENER]], ['A' => ['depends_on' => []], 'B' => ['depends_on' => ['A']]]);
    $layers = [['B'], ['A']];

    expect(rule_set_counts($context, [], $layers))->toMatchArray(['cycles' => 0, 'undeclared_dependency' => 1, 'layers' => 1])
        ->and(rule_set_counts($context, ['cycles' => true], $layers))->toMatchArray(['cycles' => 2, 'undeclared_dependency' => 1, 'layers' => 1])
        ->and(rule_set_counts($context, ['depends_on' => false], $layers))->toMatchArray(['cycles' => 0, 'undeclared_dependency' => 0, 'layers' => 0])
        ->and(rule_set_counts($context, ['cycles' => false, 'depends_on' => true], $layers))->toMatchArray(['cycles' => 0, 'undeclared_dependency' => 1, 'layers' => 1]);
});

it('counts a listener as using a module for unused_dependency, unless depends_on says not', function () {
    $context = rule_set_context([['B', 'A', Reference::LISTENER]], ['B' => ['depends_on' => ['A']]]);

    expect(rule_set_counts($context)['unused_dependency'])->toBe(0)
        ->and(rule_set_counts($context, ['depends_on' => true])['unused_dependency'])->toBe(0)
        ->and(rule_set_counts($context, ['depends_on' => false])['unused_dependency'])->toBe(1);
});

it('reads only true as turning a listener flag on', function () {
    $listener = rule_set_context([['B', 'A', Reference::LISTENER]], ['B' => ['depends_on' => []]]);
    $cycle = rule_set_context([['A', 'B', Reference::USE], ['B', 'A', Reference::LISTENER]]);

    expect(rule_set_counts($listener, ['depends_on' => 0])['undeclared_dependency'])->toBe(0)
        ->and(rule_set_counts($cycle, ['cycles' => 1])['cycles'])->toBe(0);
});

it('ignores what is not a layer and what is not a module name in the layers config', function () {
    // Module "5" is the integer 5 in a layer list: it is not a name, so it belongs to no layer.
    $context = rule_set_context([['5', 'A', Reference::USE]]);

    expect(rule_set_counts($context, [], [['A'], [5, 'B']])['layers'])->toBe(0)
        ->and(rule_set_counts($context, [], [['A'], ['5', 'B']])['layers'])->toBe(1)
        ->and(rule_set_counts($context, [], ['not a layer', ['A'], ['5']])['layers'])->toBe(1);
});

it('leaves out the custom rules set to off without dropping the ones after them', function () {
    $rules = RuleSet::fromConfig(['cycles' => false, DeclareDependenciesRule::class => 'off', WarnSeverityRule::class]);

    expect(array_map(fn (Rule $rule) => $rule->id(), $rules))->toBe(['internal_access', 'undeclared_dependency', 'unused_dependency', 'host_access', 'layers', 'public_api_leak', 'warn_severity']);
});

it('builds the rules together with their severities', function () {
    [$rules, $severities] = RuleSet::build(['cycles' => 'warn', 'internal_access' => 'off', DeclareDependenciesRule::class => 'warn', WarnSeverityRule::class]);

    expect(array_map(fn (Rule $rule) => $rule->id(), $rules))->toBe(['undeclared_dependency', 'cycles', 'unused_dependency', 'host_access', 'layers', 'public_api_leak', 'declare_dependencies', 'warn_severity'])
        ->and($severities)->toBe([
            'undeclared_dependency' => Severity::Error,
            'cycles' => Severity::Warn,
            'unused_dependency' => Severity::Warn,
            'host_access' => Severity::Warn,
            'layers' => Severity::Error,
            'public_api_leak' => Severity::Warn,
            'declare_dependencies' => Severity::Warn,
            'warn_severity' => Severity::Error,
        ]);
});

it('lets a config override the default severity of the checks added after 0.3', function () {
    [, $severities] = RuleSet::build(['unused_dependency' => 'error', 'host_access' => true, 'public_api_leak' => 'error']);

    expect($severities['unused_dependency'])->toBe(Severity::Error)
        ->and($severities['host_access'])->toBe(Severity::Error)
        ->and($severities['public_api_leak'])->toBe(Severity::Error);
});

it('gives the severity of every rule that was built, whatever the config says about the others', function () {
    $config = [DeclareDependenciesRule::class => 'warn', 'cycles' => 'warn', 'internal_acess' => 'off'];
    $rules = RuleSet::fromConfig(['cycles' => 'warn', DeclareDependenciesRule::class => 'warn']);

    expect(RuleSet::severities($config, $rules)['declare_dependencies'])->toBe(Severity::Warn)
        ->and(RuleSet::severities($config, $rules)['cycles'])->toBe(Severity::Warn)
        ->and(RuleSet::severities($config, $rules)['internal_access'])->toBe(Severity::Error);
});

it('treats a custom rule it has no config for as an error', function () {
    expect(RuleSet::severities([], [new DeclareDependenciesRule]))->toBe(['declare_dependencies' => Severity::Error]);
});

it('names the unknown rules and the entries that are not rules', function (array $config, string $message) {
    expect(RuleSet::errors($config))->toBe([$message]);
})->with([
    'a misspelled id' => [['internal_acess' => 'warn'], 'Unknown rule [internal_acess] in rules. Did you mean [internal_access]? Built-in rules: internal_access, undeclared_dependency, cycles, unused_dependency, host_access, layers, public_api_leak.'],
    'a severity in a list' => [['warn'], 'Unknown rule [0] in rules. Built-in rules: internal_access, undeclared_dependency, cycles, unused_dependency, host_access, layers, public_api_leak.'],
    'a missing class as the key' => [['App\\Missing\\Rule' => 'warn'], 'Custom rule [App\\Missing\\Rule] must be an existing class that implements Cordon\\Contracts\\Rule.'],
    'an existing class without a namespace as the key' => [['stdClass' => 'warn'], 'Custom rule [stdClass] must be an existing class that implements Cordon\\Contracts\\Rule.'],
    'an existing class that is not a rule' => [[stdClass::class], 'Custom rule [stdClass] must be an existing class that implements Cordon\\Contracts\\Rule.'],
    'a rule with an invalid severity' => [[WarnSeverityRule::class => 'loud'], 'Rule [Cordon\\Tests\\Fixtures\\Rules\\WarnSeverityRule] has an invalid value [loud]. Use error, warn or off (or true/false).'],
    'a built-in rule with an invalid severity' => [['cycles' => 3], 'Rule [cycles] has an invalid value [3]. Use error, warn or off (or true/false).'],
]);

it('refuses to build a config with an unknown rule', function () {
    expect(fn () => RuleSet::fromConfig(['internal_acess' => 'warn']))->toThrow(InvalidArgumentException::class, 'Unknown rule [internal_acess] in rules. Did you mean [internal_access]?')
        ->and(fn () => RuleSet::fromConfig(['warn']))->toThrow(InvalidArgumentException::class, 'Unknown rule [0] in rules.');
});

it('accepts built-in rules written as true, false and every severity', function () {
    expect(RuleSet::errors(['internal_access' => true, 'cycles' => false, 'layers' => 'error', 'host_access' => 'warn', 'public_api_leak' => 'off']))->toBe([]);
});

it('does not build a rule twice when the config lists a class and names it', function () {
    expect(fn () => RuleSet::fromConfig([DeclareDependenciesRule::class, 'again' => DeclareDependenciesRule::class]))->toThrow(InvalidArgumentException::class, 'uses the id [declare_dependencies], which is already taken');
});
