<?php

use Cordon\Reporters\JsonReporter;
use Cordon\Tests\Fixtures\Rules\DeclareDependenciesRule;
use JsonSchema\Validator;

const REPORT_SCHEMA = __DIR__.'/../../resources/schemas/report-v1.schema.json';

/**
 * @param  array<array-key, mixed>  $rules
 */
function json_report(array $rules = []): string
{
    return (new JsonReporter)->render(analyse_namespace_app(rules: $rules, listeners: ['cycles' => true]));
}

/**
 * @return list<string> Schema errors, empty when $json is valid.
 */
function schema_errors(string $json): array
{
    $validator = new Validator;
    $data = json_decode($json);
    $validator->validate($data, (object) ['$ref' => 'file://'.realpath(REPORT_SCHEMA)]);

    return array_map(fn (array $error) => $error['property'].': '.$error['message'], $validator->getErrors());
}

it('starts with the version of its format', function () {
    expect(json_report())->toStartWith("{\n    \"version\": 1,")
        ->and(JsonReporter::VERSION)->toBe(1);
});

it('matches its published schema', function (array $rules) {
    expect(schema_errors(json_report($rules)))->toBe([]);
})->with([
    'with violations' => [[]],
    'with warnings only' => [['internal_access' => 'warn', 'undeclared_dependency' => 'warn', 'cycles' => 'warn']],
    'empty' => [['internal_access' => 'off', 'undeclared_dependency' => 'off', 'cycles' => 'off']],
]);

it('accepts new optional fields, so adding one keeps version 1', function () {
    $report = json_decode(json_report(), true);
    $report['extra'] = 1;
    $report['summary']['extra'] = 1;
    $report['violations'][0]['extra'] = 1;

    expect(schema_errors(json_encode($report)))->toBe([]);
});

it('still accepts a version 1 report written before docs_url existed', function () {
    $report = json_decode(json_report(), true);

    foreach ($report['violations'] as &$violation) {
        unset($violation['docs_url']);
    }

    expect(schema_errors(json_encode($report)))->toBe([]);
});

it('requires the fields of version 1', function (Closure $remove) {
    $report = json_decode(json_report(), true);
    $remove($report);

    expect(schema_errors(json_encode($report)))->not->toBe([]);
})->with([
    'the rule of a violation' => [function (array &$report) {
        unset($report['violations'][0]['rule']);
    }],
    'the number of violations' => [function (array &$report) {
        unset($report['summary']['violations']);
    }],
    'the version' => [function (array &$report) {
        unset($report['version']);
    }],
]);

it('links each violation of a built-in rule to its documentation', function () {
    $report = json_decode(json_report(), true);
    $urls = array_unique(array_map(fn (array $violation) => $violation['rule'].' '.$violation['docs_url'], $report['violations']));
    sort($urls);

    expect($urls)->toBe([
        'cycles https://chrisabner.github.io/Cordon-Modulith/guide/rules#cycles',
        'internal_access https://chrisabner.github.io/Cordon-Modulith/guide/rules#internal-access',
    ]);
});

it('gives custom rules no documentation link', function () {
    $result = analyse_namespace_app(['Shared' => ['open' => true], 'Catalog' => ['depends_on' => ['Billing']]], ['internal_access' => 'off', 'undeclared_dependency' => 'off', 'cycles' => 'off', DeclareDependenciesRule::class]);

    expect(array_unique(array_column(json_decode((new JsonReporter)->render($result), true)['violations'], 'docs_url')))->toBe([null]);
});
