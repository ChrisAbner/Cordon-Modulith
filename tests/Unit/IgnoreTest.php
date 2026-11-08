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
function analyse_ignores(string $root, array $rules = []): Result
{
    $built = RuleSet::fromConfig($rules);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve(), $root);
}

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function ignore_targets(array $violations): array
{
    return array_map(fn (Violation $violation) => $violation->rule.' '.$violation->target, $violations);
}

it('ignores a violation on the line of the directive or the next one', function () {
    $result = analyse_ignores(fixture_path('ignore-app'));

    expect(ignore_targets($result->violations))->toBe(['internal_access App\\Modules\\Catalog\\Models\\Stock'])
        ->and($result->ignored)->toBe(2)
        ->and($result->ignoreErrors)->toBe([]);
});

it('ignores warnings too', function () {
    $result = analyse_ignores(fixture_path('ignore-app'), ['internal_access' => 'warn']);

    expect(ignore_targets($result->warnings))->toBe(['internal_access App\\Modules\\Catalog\\Models\\Stock'])
        ->and($result->ignored)->toBe(2);
});

it('warns about each rule of a directive that no longer matches a violation', function () {
    expect(analyse_ignores(fixture_path('ignore-app'))->ignoreWarnings)->toBe([
        'Cordon ignore directive in app/Modules/Billing/Catalogue.php:9 (internal_access) no longer matches a violation; remove it.',
        'Cordon ignore directive in app/Modules/Billing/Invoice.php:16 (undeclared_dependency) no longer matches a violation; remove it.',
    ]);
});

it('rejects directives without a reason, with an unknown rule or with a rule that has no line', function () {
    $root = sys_get_temp_dir().'/cordon-ignore-'.bin2hex(random_bytes(4));
    mkdir($root.'/app/Modules/Billing', 0777, true);
    mkdir($root.'/app/Modules/Catalog/Models', 0777, true);
    file_put_contents($root.'/app/Modules/Catalog/Models/Product.php', "<?php\n\nnamespace App\\Modules\\Catalog\\Models;\n\nfinal class Product {}\n");
    file_put_contents($root.'/app/Modules/Billing/Broken.php', implode("\n", [
        '<?php',
        '',
        'namespace App\\Modules\\Billing;',
        '',
        '// @cordon-ignore internal_access',
        'new \\App\\Modules\\Catalog\\Models\\Product;',
        '// @cordon-ignore internal_acess (a typo)',
        'new \\App\\Modules\\Catalog\\Models\\Product;',
        '// @cordon-ignore cycles (a cycle has no line)',
        '',
    ]));

    expect(analyse_ignores($root)->ignoreErrors)->toBe([
        'Cordon ignore directive in app/Modules/Billing/Broken.php:5 needs a reason in parentheses: // @cordon-ignore internal_access (why this is accepted).',
        'Cordon ignore directive in app/Modules/Billing/Broken.php:7 names unknown rule [internal_acess]. Did you mean [internal_access]?',
        'Cordon ignore directive in app/Modules/Billing/Broken.php:9 names [cycles], which reports no line to ignore: record it in the baseline instead.',
    ]);
});
