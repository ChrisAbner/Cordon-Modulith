<?php

use Cordon\Documentation\GraphExporter;
use Illuminate\Support\Facades\Artisan;

/**
 * @param  array<string, mixed>  $options
 */
function graph(array $options = []): string
{
    Artisan::call('cordon:graph', $options);

    return Artisan::output();
}

it('exports the module graph as JSON', function () {
    $graph = json_decode(graph(['--format' => 'json']), true);
    $edges = [];

    foreach ($graph['edges'] as $edge) {
        $edges[$edge['from'].' -> '.$edge['to']] = $edge;
    }

    expect($graph['modules'])->toBe(['Billing', 'Catalog', 'Shared'])
        ->and(array_keys($edges))->toBe(['Billing -> Catalog', 'Billing -> Shared', 'Catalog -> Billing'])
        ->and($edges['Billing -> Catalog'])->toMatchArray(['references' => 4, 'kinds' => ['use'], 'violations' => 2])
        ->and($edges['Catalog -> Billing'])->toMatchArray(['kinds' => ['listener'], 'violations' => 0]);
});

it('exports the module graph as Mermaid by default, with the edges that have violations in red', function () {
    $output = graph();

    expect($output)->toStartWith('flowchart LR')
        ->toContain('m0["Billing"]')
        ->toContain('m0 -->|4| m1')
        ->toContain('linkStyle 0 stroke:#d33')
        ->toContain('m1 -.->|1| m0')
        ->not->toContain('```');
});

it('labels every format with the number of references, not of classes', function () {
    $model = ['modules' => ['A', 'B'], 'edges' => [['from' => 'A', 'to' => 'B', 'references' => 5, 'kinds' => ['use'], 'violations' => 0]]];

    expect(GraphExporter::flowchart($model))->toBe(implode(PHP_EOL, ['flowchart LR', '    m0["A"]', '    m1["B"]', '    m0 -->|5| m1']).PHP_EOL)
        ->and(GraphExporter::dot($model))->toContain('"A" -> "B" [label="5"];');
});

it('exports the module graph as DOT, with listener-only edges dashed', function () {
    $output = graph(['--format' => 'dot']);

    expect($output)->toStartWith("digraph cordon {\n")
        ->toEndWith("}\n")
        ->toContain('"Billing" -> "Catalog" [label="4", color="#dd3333"];')
        ->toContain('"Catalog" -> "Billing" [label="1", style="dashed"];');
});

it('keeps a module and its neighbours with --module', function () {
    $graph = json_decode(graph(['--format' => 'json', '--module' => 'Shared']), true);

    expect($graph['modules'])->toBe(['Billing', 'Shared'])
        ->and(array_map(fn (array $edge) => $edge['from'].' -> '.$edge['to'], $graph['edges']))->toBe(['Billing -> Shared']);
});

it('produces the same graph on every run', function (string $format) {
    expect(graph(['--format' => $format]))->toBe(graph(['--format' => $format]));
})->with(['mermaid', 'dot', 'json']);

it('rejects unknown formats and modules', function () {
    expect(Artisan::call('cordon:graph', ['--format' => 'svg']))->toBe(2)
        ->and(Artisan::output())->toContain('Unknown format [svg]. Use mermaid, dot or json.')
        ->and(Artisan::call('cordon:graph', ['--module' => 'Biling']))->toBe(2);
});
