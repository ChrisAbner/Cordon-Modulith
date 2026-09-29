<?php

use Cordon\Analysis\Violation;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Fixtures\Rules\DeclareDependenciesRule;
use Cordon\Tests\Fixtures\Rules\DuplicateIdRule;

it('runs custom rules after the built-in ones', function () {
    $rules = RuleSet::fromConfig(['cycles' => false, DeclareDependenciesRule::class]);

    expect(array_map(fn ($rule) => $rule->id(), $rules))->toBe(['internal_access', 'undeclared_dependency', 'declare_dependencies']);
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

it('rejects classes that are not rules and duplicated ids', function (array $config, string $message) {
    expect(fn () => RuleSet::fromConfig($config))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing class' => [['App\\Missing\\Rule'], 'must be an existing class that implements'],
    'not a rule' => [[stdClass::class], 'must be an existing class that implements'],
    'duplicated id' => [[DuplicateIdRule::class], 'uses the id [cycles], which is already taken'],
]);
