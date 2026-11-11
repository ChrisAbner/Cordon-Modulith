<?php

use Cordon\Analysis\Violation;
use Cordon\Rules\RuleSet;
use Cordon\Rules\Severity;
use Cordon\Tests\Fixtures\Rules\DeclareDependenciesRule;
use Cordon\Tests\Fixtures\Rules\DecoratedRule;
use Cordon\Tests\Fixtures\Rules\DuplicateIdRule;
use Cordon\Tests\Fixtures\Rules\WarnSeverityRule;

it('runs custom rules after the built-in ones', function () {
    $rules = RuleSet::fromConfig(['cycles' => false, DeclareDependenciesRule::class]);

    expect(array_map(fn ($rule) => $rule->id(), $rules))->toBe(['internal_access', 'undeclared_dependency', 'unused_dependency', 'host_access', 'layers', 'public_api_leak', 'declare_dependencies']);
});

it('reports custom rule violations', function () {
    $result = analyse_namespace_app(
        ['Shared' => ['open' => true], 'Catalog' => ['depends_on' => ['Billing']]],
        ['internal_access' => false, 'undeclared_dependency' => false, 'cycles' => false, 'mine' => DeclareDependenciesRule::class],
    );

    expect(array_map(fn (Violation $v) => $v->sourceModule, $result->violations))->toBe(['Billing', 'Shared']);
});

it('builds custom rules with the given factory', function () {
    $made = [];
    RuleSet::fromConfig([DeclareDependenciesRule::class], function (string $class) use (&$made) {
        $made[] = $class;

        return new $class;
    });

    expect($made)->toBe([DeclareDependenciesRule::class]);
});

it('turns a built-in rule off or into a warning', function () {
    $off = RuleSet::fromConfig(['cycles' => 'off']);
    $warn = RuleSet::fromConfig(['cycles' => 'warn']);

    expect(array_map(fn ($rule) => $rule->id(), $off))->toBe(['internal_access', 'undeclared_dependency', 'unused_dependency', 'host_access', 'layers', 'public_api_leak'])
        ->and(array_map(fn ($rule) => $rule->id(), $warn))->toBe(['internal_access', 'undeclared_dependency', 'cycles', 'unused_dependency', 'host_access', 'layers', 'public_api_leak'])
        ->and(RuleSet::severities(['cycles' => 'warn'], $warn))->toBe([
            'internal_access' => Severity::Error,
            'undeclared_dependency' => Severity::Error,
            'cycles' => Severity::Warn,
            'unused_dependency' => Severity::Warn,
            'host_access' => Severity::Warn,
            'layers' => Severity::Error,
            'public_api_leak' => Severity::Warn,
        ]);
});

it('gives custom rules a severity when their class is the key', function () {
    $listed = RuleSet::fromConfig(['cycles' => false, DeclareDependenciesRule::class]);
    $named = RuleSet::fromConfig(['cycles' => false, 'mine' => DeclareDependenciesRule::class]);
    $warn = RuleSet::fromConfig(['cycles' => false, DeclareDependenciesRule::class => 'warn']);
    $off = RuleSet::fromConfig(['cycles' => false, DeclareDependenciesRule::class => 'off']);

    expect(RuleSet::severities([DeclareDependenciesRule::class], $listed)['declare_dependencies'])->toBe(Severity::Error)
        ->and(RuleSet::severities(['mine' => DeclareDependenciesRule::class], $named)['declare_dependencies'])->toBe(Severity::Error)
        ->and(RuleSet::severities([DeclareDependenciesRule::class => 'warn'], $warn)['declare_dependencies'])->toBe(Severity::Warn)
        ->and(array_map(fn ($rule) => $rule->id(), $off))->toBe(['internal_access', 'undeclared_dependency', 'unused_dependency', 'host_access', 'layers', 'public_api_leak']);
});

it('lists the problems in the rules config', function () {
    expect(RuleSet::errors([
        'internal_acess' => false,
        'cycles' => 'warning',
        'App\\Missing\\Rule',
        DeclareDependenciesRule::class => 'loud',
    ]))->toBe([
        'Unknown rule [internal_acess] in rules. Did you mean [internal_access]? Built-in rules: internal_access, undeclared_dependency, cycles, unused_dependency, host_access, layers, public_api_leak.',
        'Rule [cycles] has an invalid value [warning]. Use error, warn or off (or true/false).',
        'Custom rule [App\\Missing\\Rule] must be an existing class that implements Cordon\\Contracts\\Rule.',
        'Rule [Cordon\\Tests\\Fixtures\\Rules\\DeclareDependenciesRule] has an invalid value [loud]. Use error, warn or off (or true/false).',
    ])->and(RuleSet::errors(['cycles' => 'warn', DeclareDependenciesRule::class, 'mine' => DeclareDependenciesRule::class]))->toBe([]);
});

it('rejects classes that are not rules and duplicated ids', function (array $config, string $message) {
    expect(fn () => RuleSet::fromConfig($config))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing class' => [['App\\Missing\\Rule'], 'must be an existing class that implements'],
    'not a rule' => [[stdClass::class], 'must be an existing class that implements'],
    'duplicated id' => [[DuplicateIdRule::class], 'uses the id [cycles], which is already taken'],
]);

it('keeps the severity of a custom rule that the container decorates (M3)', function () {
    $make = fn (string $class) => new DecoratedRule(new $class);
    [$rules, $severities] = RuleSet::build([DeclareDependenciesRule::class => 'warn'], $make);

    expect(array_map(fn ($rule) => $rule->id(), $rules))->toContain('declare_dependencies')
        ->and($severities['declare_dependencies'])->toBe(Severity::Warn)
        ->and($severities['cycles'])->toBe(Severity::Error)
        ->and($severities['unused_dependency'])->toBe(Severity::Warn);
});

it('reports the violations of a rule in error as errors, whatever severity they claim (M5)', function () {
    $result = analyse_namespace_app(rules: [WarnSeverityRule::class]);
    $mine = array_values(array_filter($result->violations, fn (Violation $violation) => $violation->rule === 'warn_severity'));

    expect($mine)->toHaveCount(1)
        ->and($mine[0]->severity)->toBe(Severity::Error)
        ->and($mine[0]->toArray()['severity'])->toBe('error');
});
