<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

/**
 * tests/Fixtures/host-app analysed with the code outside its modules under $hostPaths.
 *
 * @param  list<string>  $hostPaths  Relative to the fixture.
 * @param  array<string, mixed>  $modules
 */
function analyse_host_app(array $hostPaths = ['app', 'routes', 'database'], array $modules = []): Result
{
    $root = fixture_path('host-app');
    $map = (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve()->configure($modules);

    return host_analyzer()->analyze($map, $root, array_map(fn (string $path) => $root.'/'.$path, $hostPaths));
}

function host_analyzer(): Analyzer
{
    $rules = RuleSet::fromConfig();

    return new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $rules, RuleSet::severities([], $rules));
}

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function host_findings(array $violations): array
{
    $found = [];

    foreach ($violations as $violation) {
        $found[] = $violation->rule.' '.substr((string) $violation->file, strlen(fixture_path('host-app')) + 1).' '.$violation->target;
    }

    sort($found);

    return $found;
}

it('warns about the code outside the modules that uses their internals', function () {
    $result = analyse_host_app();

    expect(host_findings($result->warnings))->toBe([
        'host_access app/Filament/Resources/ProductResource.php App\\Modules\\Catalog\\Models\\Product',
        'host_access app/Http/Controllers/OrderController.php App\\Modules\\Catalog\\Models\\Product',
        'host_access app/Http/Controllers/OrderController.php App\\Modules\\Orders\\Http\\OrderPage',
        'host_access routes/web.php App\\Modules\\Catalog\\Models\\Product',
    ])
        ->and($result->warnings[0]->sourceModule)->toBe('host')
        ->and($result->warnings[0]->docsUrl())->toEndWith('/guide/rules#host-access')
        ->and($result->warnings[0]->message)->toStartWith('Code outside the modules uses App\\Modules\\Catalog\\Models\\Product, which is internal to module [Catalog].');
});

it('changes nothing when host.paths is empty', function () {
    $without = analyse_host_app([]);
    $root = fixture_path('host-app');
    $plain = host_analyzer()->analyze((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve(), $root);

    expect($without)->toEqual($plain)
        ->and(host_findings($without->violations))->toBe(['internal_access app/Modules/Orders/Http/OrderPage.php App\\Modules\\Catalog\\Models\\Product']);
});

it('does not count a module file as code outside the modules', function () {
    $result = analyse_host_app();

    expect(host_findings($result->violations))->toBe(['internal_access app/Modules/Orders/Http/OrderPage.php App\\Modules\\Catalog\\Models\\Product'])
        ->and($result->filesAnalysed)->toBe(6);
});

it('keeps the code outside the modules out of cycles and depends_on', function () {
    $result = analyse_host_app(modules: ['Catalog' => ['depends_on' => []], 'Orders' => ['depends_on' => []]]);
    $rules = array_unique(array_map(fn (Violation $violation) => $violation->rule, [...$result->violations, ...$result->warnings]));

    expect($rules)->not->toContain('cycles')
        ->and(array_filter([...$result->violations, ...$result->warnings], fn (Violation $violation) => $violation->rule !== 'host_access' && $violation->sourceModule === 'host'))->toBe([]);
});
