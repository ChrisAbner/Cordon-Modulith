<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Snapshot;
use Cordon\Baseline\Baseline;
use Cordon\Documentation\DocumentationGenerator;
use Cordon\Documentation\EventInventory;
use Cordon\Documentation\GraphExporter;
use Cordon\Laravel\ResolverFactory;
use Cordon\Reporters\CheckstyleReporter;
use Cordon\Reporters\GithubReporter;
use Cordon\Reporters\GitlabReporter;
use Cordon\Reporters\JsonReporter;
use Cordon\Reporters\JunitReporter;
use Cordon\Reporters\TextReporter;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Oracle\Temp;
use Illuminate\Config\Repository;

/**
 * The same snapshot with its files, edges and signatures in another order,
 * as another file system or resolver could give them.
 *
 * @template T
 *
 * @param  list<T>  $items
 * @return list<T>
 */
function seeded_shuffle(array $items, int $seed): array
{
    mt_srand($seed);
    shuffle($items);
    mt_srand();

    return $items;
}

function shuffled_snapshot(Snapshot $snapshot, int $seed): Snapshot
{
    $context = $snapshot->context;

    return new Snapshot(
        new AnalysisContext(
            $context->modules,
            $context->symbols,
            $context->policy,
            seeded_shuffle($context->edges, $seed),
            $context->parseErrors,
            seeded_shuffle($context->hostEdges, $seed),
            seeded_shuffle($context->signatures, $seed),
        ),
        seeded_shuffle($snapshot->analyses, $seed),
        $snapshot->parseErrors,
    );
}

/**
 * Every output Cordon writes for a snapshot, by name.
 *
 * @return array<string, string>
 */
function every_output(Analyzer $analyzer, Snapshot $snapshot, string $base): array
{
    $result = $analyzer->check($snapshot, $base);
    $outputs = [];

    foreach (['text' => new TextReporter, 'json' => new JsonReporter, 'github' => new GithubReporter, 'gitlab' => new GitlabReporter, 'junit' => new JunitReporter, 'checkstyle' => new CheckstyleReporter] as $name => $reporter) {
        $outputs[$name] = $reporter->render($result);
    }

    $baseline = Temp::removeAtExit(sys_get_temp_dir().'/cordon-determinism-'.bin2hex(random_bytes(4)).'.json');
    Baseline::fromViolations($result->violations, $base)->save($baseline);
    $outputs['baseline'] = (string) file_get_contents($baseline);

    foreach ((new DocumentationGenerator)->generate($snapshot, $result->violations, EventInventory::build($snapshot), $base) as $file => $content) {
        $outputs['docs '.$file] = $content;
    }

    $model = GraphExporter::model($result->modules->names(), $snapshot->context->edges, $result->violations);
    $outputs['graph json'] = (string) json_encode($model);
    $outputs['graph dot'] = GraphExporter::dot($model);
    $outputs['graph mermaid'] = GraphExporter::flowchart($model);

    return $outputs;
}

it('writes the same bytes whatever the order of the files', function (string $fixture, string $resolver) {
    $base = fixture_path($fixture);
    $defaults = require dirname(__DIR__, 2).'/config/cordon.php';
    $expected = is_file($base.'/expected.json') ? json_decode((string) file_get_contents($base.'/expected.json'), true) : [];
    $cordon = array_replace($defaults, ['resolver' => $resolver, 'listeners' => ['cycles' => true]], $expected['config'] ?? []);
    $modules = ResolverFactory::make(new Repository([...($expected['laravel'] ?? []), 'cordon' => $cordon]), $base)->resolve()->configure($cordon['modules']);
    $rules = RuleSet::fromConfig(['unused_dependency' => 'error'], null, ['cycles' => true]);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $rules, RuleSet::severities(['unused_dependency' => 'error'], $rules));
    $snapshot = $analyzer->snapshot($modules);
    $outputs = every_output($analyzer, $snapshot, $base);

    // Empty outputs would be equal in any order: each project has dependencies and violations.
    expect(json_decode($outputs['graph json'], true)['edges'])->not->toBe([])
        ->and(json_decode($outputs['json'], true)['summary']['violations'] + json_decode($outputs['json'], true)['summary']['warnings'])->toBeGreaterThan(0);

    foreach ([7, 42, 1999] as $seed) {
        expect(every_output($analyzer, shuffled_snapshot($snapshot, $seed), $base))->toBe($outputs);
    }
})->with([
    'namespace app' => ['namespace-app', 'namespace'],
    'events app' => ['events-app', 'namespace'],
    'nwidart shop' => ['real-nwidart-shop', 'nwidart'],
]);
