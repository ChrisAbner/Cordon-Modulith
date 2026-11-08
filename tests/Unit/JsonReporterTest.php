<?php

use Cordon\Reporters\JsonReporter;
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

it('has a schema that rejects fields it does not know, so a new field needs a new version', function () {
    $report = json_decode(json_report(), true);

    $summary = $report;
    $summary['summary']['extra'] = 1;

    $violation = $report;
    $violation['violations'][0]['extra'] = 1;

    $root = $report;
    $root['extra'] = 1;

    expect(schema_errors(json_encode($summary)))->not->toBe([])
        ->and(schema_errors(json_encode($violation)))->not->toBe([])
        ->and(schema_errors(json_encode($root)))->not->toBe([]);
});
