<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Violation;
use Cordon\Laravel\ResolverFactory;
use Cordon\Rules\RuleSet;
use Illuminate\Config\Repository;

/**
 * Every tests/Fixtures/real-* directory is an anonymised copy of a real module
 * layout. Its expected.json holds the Cordon config and the reviewed list of
 * violations, as "rule|relative file|target" keys (the baseline identity).
 *
 * @return array<string, array{0: string}>
 */
function real_projects(): array
{
    $projects = [];

    foreach (glob(fixture_path('real-*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $projects[basename($directory)] = [$directory];
    }

    return $projects;
}

it('reports exactly the reviewed violations of each real project', function (string $directory) {
    $expected = json_decode((string) file_get_contents($directory.'/expected.json'), true, 512, JSON_THROW_ON_ERROR);
    $defaults = require dirname(__DIR__, 2).'/config/cordon.php';
    $cordon = array_replace($defaults, $expected['config'] ?? []);
    $config = new Repository([...($expected['laravel'] ?? []), 'cordon' => $cordon]);

    expect(ResolverFactory::driver($config, $directory))->toBe($expected['resolver']);

    $modules = ResolverFactory::make($config, $directory)->resolve();

    expect($modules->names())->toBe($expected['modules'])
        ->and($modules->configurationWarnings($cordon['modules']))->toBe([]);

    $analyzer = new Analyzer(
        new PhpParserExtractor,
        new FileCollector($cordon['exclude']),
        new PublicApiPolicy($cordon['public_namespaces']),
        RuleSet::fromConfig($cordon['rules']),
    );

    $result = $analyzer->analyze($modules->configure($cordon['modules']), $directory);
    $keys = array_map(fn (Violation $violation) => $violation->baselineKey($directory), $result->violations);
    sort($keys);

    $expectedKeys = $expected['violations'];
    sort($expectedKeys);

    expect($result->parseErrors)->toBe([])
        ->and($keys)->toBe($expectedKeys);
})->with(real_projects());

it('has at least five real project fixtures', function () {
    expect(count(real_projects()))->toBeGreaterThanOrEqual(5);
});
