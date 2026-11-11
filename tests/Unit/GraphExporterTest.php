<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\Edge;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\Snapshot;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Documentation\GraphExporter;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

/**
 * A snapshot of hand-made modules and edges: [from, to, references] per edge.
 *
 * @param  list<Module>  $modules
 * @param  list<array{0: string, 1: string, 2?: int}>  $links
 */
function c4_snapshot(array $modules, array $links = []): Snapshot
{
    $map = new ModuleMap($modules);
    $edges = [];

    foreach ($links as $link) {
        for ($i = 0; $i < ($link[2] ?? 1); $i++) {
            $edges[] = new Edge($map->get($link[0]), $map->get($link[1]), new Reference('/app/'.$link[0].'.php', null, $link[1].'\\Thing', $i + 1));
        }
    }

    return new Snapshot(new AnalysisContext($map, SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges), []);
}

function c4_module(string $name, array $metadata = [], ?string $parent = null): Module
{
    return new Module($name, 'App\\'.str_replace('.', '\\', $name), '/app/'.$name, metadata: $metadata, parent: $parent);
}

function c4_violation(string $from, string $to): Violation
{
    return new Violation('internal_access', 'message', null, null, $from, $to, $to.'\\Thing');
}

it('exports the events-app modules as a C4 diagram with a stable output', function () {
    $modules = (new NamespaceResolver(fixture_path('events-app/app/Modules'), 'App\\Modules'))->resolve();
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig());
    $snapshot = $analyzer->snapshot($modules);
    $violations = $analyzer->check($snapshot)->violations;
    $c4 = GraphExporter::c4($snapshot, $violations);

    expect($c4)->toStartWith("@startuml\n!include <C4/C4_Component>\n")
        ->toEndWith("@enduml\n")
        ->toContain("Container_Boundary(app, \"Application\") {\n    Component(m0, \"Billing\", \"Module\")\n    Component(m1, \"Orders\", \"Module\")\n    Component(m2, \"Shipping\", \"Module\")\n}\n")
        ->and(substr_count($c4, 'Container_Boundary('))->toBe(1)
        ->and(substr_count($c4, "\nRel("))->toBe(count(array_unique(array_map(fn (Edge $edge) => $edge->from->name.'|'.$edge->to->name, $snapshot->context->edges))))
        ->and($c4)->toBe(GraphExporter::c4($snapshot, $violations));
});

it('draws a C4 relationship per dependency with its number of references, in red with violations', function () {
    $snapshot = c4_snapshot([c4_module('Billing'), c4_module('Catalog'), c4_module('Shared')], [['Billing', 'Catalog', 4], ['Billing', 'Shared', 1], ['Catalog', 'Billing', 2]]);

    expect(GraphExporter::c4($snapshot, [c4_violation('Billing', 'Catalog'), c4_violation('Billing', 'Catalog')]))->toBe(implode(PHP_EOL, [
        '@startuml',
        '!include <C4/C4_Component>',
        'LAYOUT_LEFT_RIGHT()',
        'Container_Boundary(app, "Application") {',
        '    Component(m0, "Billing", "Module")',
        '    Component(m1, "Catalog", "Module")',
        '    Component(m2, "Shared", "Module")',
        '}',
        'Rel(m0, m1, "4")',
        'UpdateRelStyle(m0, m1, $textColor="#d33", $lineColor="#d33")',
        'Rel(m0, m2, "1")',
        'Rel(m1, m0, "2")',
        '@enduml',
    ]).PHP_EOL);
});

it('describes a module in C4 with the description of its metadata', function () {
    $snapshot = c4_snapshot([c4_module('Billing', ['description' => "  Takes payments.\n", 'owner' => 'payments-team']), c4_module('Catalog', ['description' => '']), c4_module('Orders', ['description' => 42])]);

    expect(GraphExporter::c4($snapshot, []))
        ->toContain('    Component(m0, "Billing", "Module", "Takes payments.")')
        ->toContain('    Component(m1, "Catalog", "Module")')
        ->toContain('    Component(m2, "Orders", "Module")');
});

it('keeps an isolated module in the C4 diagram, without relationships', function () {
    $snapshot = c4_snapshot([c4_module('Alone')]);

    expect(GraphExporter::c4($snapshot, []))->toBe(implode(PHP_EOL, [
        '@startuml',
        '!include <C4/C4_Component>',
        'LAYOUT_LEFT_RIGHT()',
        'Container_Boundary(app, "Application") {',
        '    Component(m0, "Alone", "Module")',
        '}',
        '@enduml',
    ]).PHP_EOL);
});

it('escapes quotes, backslashes, percent signs and newlines in a C4 string', function () {
    $snapshot = c4_snapshot([
        c4_module('Say "hi"', ['description' => "Line one\r\nline \"two\" C:\\temp 100% %chr(34)\nlast"]),
        c4_module('Back\\slash'),
        c4_module('My Module'),
    ], [['Say "hi"', 'My Module']]);
    $c4 = GraphExporter::c4($snapshot, []);

    expect($c4)
        ->toContain('Component(m0, "Back\\\\slash", "Module")')
        ->toContain('Component(m1, "My Module", "Module")')
        ->toContain('Component(m2, "Say %chr(34)hi%chr(34)", "Module", "Line one\nline %chr(34)two%chr(34) C:\\\\temp 100%chr(37) %chr(37)chr(34)\nlast")');
    // Nothing but the delimiters is left as a quote, and a statement is one line.
    foreach (explode("\n", $c4) as $line) {
        expect(substr_count($line, '"') % 2)->toBe(0);
    }

    expect($c4)->not->toContain("\r");
});

it('draws a nested module inside a C4 boundary of its parent', function () {
    $snapshot = c4_snapshot([
        c4_module('Billing'),
        c4_module('Billing.Invoices', parent: 'Billing'),
        c4_module('Billing.Payments', ['description' => 'Cards.'], 'Billing'),
        c4_module('Orders'),
    ], [['Billing.Invoices', 'Billing.Payments', 2], ['Orders', 'Billing']]);

    expect(GraphExporter::c4($snapshot, [c4_violation('Orders', 'Billing')]))->toBe(implode(PHP_EOL, [
        '@startuml',
        '!include <C4/C4_Component>',
        'LAYOUT_LEFT_RIGHT()',
        'Container_Boundary(app, "Application") {',
        '    Boundary(b0, "Billing") {',
        '        Component(m0, "Billing", "Module")',
        '        Component(m1, "Billing.Invoices", "Module")',
        '        Component(m2, "Billing.Payments", "Module", "Cards.")',
        '    }',
        '    Component(m3, "Orders", "Module")',
        '}',
        'Rel(m1, m2, "2")',
        'Rel(m3, m0, "1")',
        'UpdateRelStyle(m3, m0, $textColor="#d33", $lineColor="#d33")',
        '@enduml',
    ]).PHP_EOL);
});

it('draws a flat C4 diagram when no module is nested', function () {
    expect(GraphExporter::c4(c4_snapshot([c4_module('A'), c4_module('B')]), []))->not->toContain('Boundary(b');
});

it('labels a C4 relationship limited to named interfaces with them', function () {
    $billing = new Module('Billing', 'App\\Billing', '/app/Billing', dependsOn: ['Catalog::Pricing'], interfaces: []);
    $catalog = new Module('Catalog', 'App\\Catalog', '/app/Catalog', interfaces: ['Pricing' => ['Pricing\\*']]);
    $map = new ModuleMap([$billing, $catalog]);
    $edges = [new Edge($map->get('Billing'), $map->get('Catalog'), new Reference('/app/Billing/A.php', null, 'App\\Catalog\\Pricing\\Quote', 3))];
    $snapshot = new Snapshot(new AnalysisContext($map, SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges), []);

    expect(GraphExporter::c4($snapshot, []))->toContain('Rel(m0, m1, "1 Catalog::Pricing")');
});

it('keeps only a module and its neighbours in C4 with a focus', function () {
    $snapshot = c4_snapshot([c4_module('A'), c4_module('B'), c4_module('C'), c4_module('D')], [['A', 'B'], ['C', 'D']]);
    $c4 = GraphExporter::c4($snapshot, [], 'A');

    expect($c4)->toContain('Component(m0, "A", "Module")')
        ->toContain('Component(m1, "B", "Module")')
        ->not->toContain('"C"')
        ->not->toContain('"D"')
        ->toContain('Rel(m0, m1, "1")');
});
