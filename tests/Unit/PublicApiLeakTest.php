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
 * @param  array<string, mixed>  $rules
 */
function analyse_leak_app(array $rules = []): Result
{
    $root = fixture_path('leak-app');
    $built = RuleSet::fromConfig($rules);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve(), $root);
}

it('warns when a public class exposes an internal one of its module', function () {
    $messages = array_map(fn (Violation $violation) => $violation->message, array_values(array_filter(
        analyse_leak_app()->warnings,
        fn (Violation $violation) => $violation->rule === 'public_api_leak',
    )));

    sort($messages);

    expect($messages)->toBe([
        'App\\Modules\\Billing\\Contracts\\Invoices::find() returns App\\Modules\\Billing\\Models\\Invoice, which is internal to [Billing]: other modules reach it through the public API.',
        'App\\Modules\\Billing\\Contracts\\Payments::$customer has type App\\Modules\\Billing\\Models\\Customer, which is internal to [Billing]: other modules reach it through the public API.',
        'App\\Modules\\Billing\\Contracts\\Payments::$last has type App\\Modules\\Billing\\Models\\Payment, which is internal to [Billing]: other modules reach it through the public API.',
        'App\\Modules\\Billing\\Contracts\\Payments::DEFAULT_STATUS has type App\\Modules\\Billing\\Models\\Status, which is internal to [Billing]: other modules reach it through the public API.',
        'App\\Modules\\Billing\\Contracts\\Payments::pay() takes App\\Modules\\Billing\\Models\\Invoice, which is internal to [Billing]: other modules reach it through the public API.',
    ]);
});

it('reports a leak once per file and internal class, at the first member', function () {
    $leaks = array_values(array_filter(analyse_leak_app()->warnings, fn (Violation $violation) => $violation->rule === 'public_api_leak' && str_ends_with((string) $violation->file, 'Payments.php')));

    expect(array_map(fn (Violation $violation) => $violation->target.':'.$violation->line, $leaks))->toBe([
        'App\\Modules\\Billing\\Models\\Status:13',
        'App\\Modules\\Billing\\Models\\Payment:15',
        'App\\Modules\\Billing\\Models\\Customer:19',
        'App\\Modules\\Billing\\Models\\Invoice:21',
    ]);
});

it('is a warning by default and can fail', function () {
    expect(array_column(array_map(fn (Violation $v) => $v->toArray(), analyse_leak_app(['public_api_leak' => 'error'])->violations), 'rule'))->toContain('public_api_leak');
});
