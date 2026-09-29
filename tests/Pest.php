<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
use Cordon\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function fixture_path(string $path = ''): string
{
    return rtrim(__DIR__.'/Fixtures/'.ltrim($path, '/'), '/');
}

/**
 * Analyse tests/Fixtures/namespace-app (modules Billing, Catalog, Shared).
 *
 * @param  array<string, mixed>  $moduleConfig
 * @param  array<string, bool>  $rules
 */
function analyse_namespace_app(array $moduleConfig = ['Shared' => ['open' => true]], array $rules = []): Result
{
    $modules = (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))
        ->resolve()
        ->configure($moduleConfig);

    $analyzer = new Analyzer(
        new PhpParserExtractor,
        new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']),
        new PublicApiPolicy,
        RuleSet::fromConfig($rules),
    );

    return $analyzer->analyze($modules, fixture_path('namespace-app'));
}

/**
 * @return list<Violation>
 */
function violations_for(Result $result, string $rule): array
{
    return array_values(array_filter($result->violations, fn (Violation $violation) => $violation->rule === $rule));
}
