<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\ModuleFilter;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Oracle\Temp;

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
        'Cordon ignore directive in app/Modules/Billing/Catalogue.php:9 (internal_access) no longer matches a violation on its line or the statement after it; remove it.',
        'Cordon ignore directive in app/Modules/Billing/Invoice.php:16 (undeclared_dependency) no longer matches a violation on its line or the statement after it; remove it.',
    ]);
});

it('rejects directives without a reason, with an unknown rule or with a rule that has no line', function () {
    $root = Temp::removeAtExit(sys_get_temp_dir().'/cordon-ignore-'.bin2hex(random_bytes(4)));
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
        '// @cordon-ignore (which rule?)',
        'new \\App\\Modules\\Catalog\\Models\\Product;',
        '',
    ]));

    expect(analyse_ignores($root)->ignoreErrors)->toBe([
        'Cordon ignore directive in app/Modules/Billing/Broken.php:5 needs a reason in parentheses: // @cordon-ignore internal_access (why this is accepted).',
        'Cordon ignore directive in app/Modules/Billing/Broken.php:7 names unknown rule [internal_acess]. Did you mean [internal_access]?',
        'Cordon ignore directive in app/Modules/Billing/Broken.php:9 names [cycles], which reports no line to ignore: record it in the baseline instead.',
        'Cordon ignore directive in app/Modules/Billing/Broken.php:10 names no rule: // @cordon-ignore <rule> (<reason>).',
    ]);
});

it('narrows what was ignored to the modules and paths a filter keeps', function () {
    $result = analyse_ignores(fixture_path('ignore-app'));

    expect(ModuleFilter::apply($result, ['Catalog'])->ignored)->toBe(0)
        ->and(ModuleFilter::apply($result, ['Catalog'])->ignoreWarnings)->toBe([])
        ->and(ModuleFilter::apply($result, ['Billing'])->ignored)->toBe(2)
        ->and(ModuleFilter::applyPaths($result, [fixture_path('ignore-app/app/Modules/Billing/Catalogue.php')])->ignoreWarnings)->toHaveCount(1)
        ->and(ModuleFilter::applyPaths($result, [fixture_path('ignore-app/app/Modules/Billing/Catalogue.php')])->ignored)->toBe(0);
});
