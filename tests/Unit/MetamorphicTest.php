<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Laravel\ResolverFactory;
use Cordon\Rules\RuleSet;
use Cordon\Support\Paths;
use Cordon\Tests\Oracle\Transformations;
use Illuminate\Config\Repository;

/**
 * The real projects and the namespace app, by name => directory.
 *
 * @return array<string, array{0: string}>
 */
function metamorphic_projects(): array
{
    $projects = ['namespace-app' => [fixture_path('namespace-app')]];

    foreach (glob(fixture_path('real-*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $projects[basename($directory)] = [$directory];
    }

    return $projects;
}

/**
 * Analyses a copy of a fixture project with the config it is tested with.
 */
function analyse_project(string $directory): Result
{
    $defaults = require dirname(__DIR__, 2).'/config/cordon.php';
    $expected = is_file($directory.'/expected.json')
        ? json_decode((string) file_get_contents($directory.'/expected.json'), true, 512, JSON_THROW_ON_ERROR)
        : ['config' => ['resolver' => 'namespace', 'modules' => ['Shared' => ['open' => true]]]];
    $cordon = array_replace($defaults, $expected['config'] ?? []);
    $config = new Repository([...($expected['laravel'] ?? []), 'cordon' => $cordon]);
    $rules = RuleSet::fromConfig($cordon['rules']);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector($cordon['exclude']), new PublicApiPolicy($cordon['public_namespaces']), $rules, RuleSet::severities($cordon['rules'], $rules));

    return $analyzer->analyze(ResolverFactory::make($config, $directory)->resolve()->configure($cordon['modules']), $directory);
}

/**
 * What a transformation may not change: the modules, the number of
 * cross-module references and the baseline keys of the violations and
 * warnings (lowercased, module directory prefix dropped).
 *
 * @param  array<string, string>  $renamed  Old module or class name => new one, to compare across a rename.
 * @return array{modules: list<string>, violations: list<string>, references: int}
 */
function shape(Result $result, string $base, array $renamed = []): array
{
    $name = fn (?string $value): string => strtr((string) $value, $renamed);

    $keys = array_map(
        fn (Violation $violation) => strtolower($name($violation->rule.'|'.preg_replace('~^(app/Modules/|Modules/)~', '', (string) Paths::relative($base, $violation->file)).'|'.$violation->target)),
        [...$result->violations, ...$result->warnings],
    );
    sort($keys);

    $modules = array_map($name, $result->modules->names());
    sort($modules);

    return ['modules' => $modules, 'violations' => $keys, 'references' => $result->crossModuleReferences];
}

it('gives the same result whatever form the class names take', function (string $project, string $form) {
    $copy = Transformations::copy($project);
    Transformations::{$form}($copy);
    $before = shape(analyse_project($project), $project);

    // A project without cross-module references would pass any transformation.
    expect($before['references'])->toBeGreaterThan(0)
        ->and(shape(analyse_project($copy), $copy))->toBe($before);
})->with(metamorphic_projects())->with(['fullyQualified', 'lowercase', 'aliased']);

it('ignores unused imports, docblocks, strings and comments', function (string $project) {
    $before = analyse_project($project);
    $copy = Transformations::copy($project);
    Transformations::noise($copy, array_values(array_unique(array_filter(array_map(fn (Violation $v) => $v->target, $before->violations), fn (?string $target) => $target !== null && ! str_contains($target, ' ')))));

    expect($before->crossModuleReferences)->toBeGreaterThan(0)
        ->and(shape(analyse_project($copy), $copy))->toBe(shape($before, $project));
})->with(metamorphic_projects());

it('maps every edge and violation when a module is renamed', function () {
    $copy = Transformations::copy(fixture_path('namespace-app'));
    Transformations::renameModule($copy, 'app/Modules', 'App\\Modules', 'Billing', 'Invoicing');

    expect(shape(analyse_project($copy), $copy))
        ->toBe(shape(analyse_project(fixture_path('namespace-app')), fixture_path('namespace-app'), ['Billing' => 'Invoicing']));
});

it('drops exactly the violations of a class moved to a public namespace', function () {
    $before = shape(analyse_project(fixture_path('namespace-app')), fixture_path('namespace-app'));
    $copy = Transformations::copy(fixture_path('namespace-app'));
    Transformations::moveClass($copy, 'App\\Modules\\Catalog\\Models\\Product', 'app/Modules/Catalog/Models/Product.php', 'App\\Modules\\Catalog\\Contracts\\Product', 'app/Modules/Catalog/Contracts/Product.php');
    $after = shape(analyse_project($copy), $copy);

    $kept = array_values(array_filter($before['violations'], fn (string $key) => ! str_ends_with($key, '|app\\modules\\catalog\\models\\product')));

    expect($kept)->not->toBe($before['violations'])
        ->and($after['violations'])->toBe($kept);
});

it('does not change the violations when a reference is repeated, only the references', function () {
    $before = analyse_project(fixture_path('namespace-app'));
    $violation = $before->violations[0];
    $copy = Transformations::copy(fixture_path('namespace-app'));
    $file = $copy.substr((string) $violation->file, strlen(fixture_path('namespace-app')));
    Transformations::duplicate($file, (string) $violation->target);
    $after = analyse_project($copy);

    expect(shape($after, $copy)['violations'])->toBe(shape($before, fixture_path('namespace-app'))['violations'])
        ->and($after->crossModuleReferences)->toBe($before->crossModuleReferences + 1);
});

it('attributes a file to the module of its directory, not of its namespace', function () {
    $copy = Transformations::copy(fixture_path('namespace-app'));
    file_put_contents($copy.'/app/Modules/Shared/Misplaced.php', "<?php\n\nnamespace App\\Modules\\Billing;\n\nfinal class Misplaced\n{\n    public function __construct(\\App\\Modules\\Catalog\\Models\\Product \$product) {}\n}\n");
    $misplaced = array_values(array_filter(analyse_project($copy)->violations, fn (Violation $v) => str_ends_with((string) $v->file, 'Misplaced.php')));

    expect($misplaced)->toHaveCount(1)
        ->and($misplaced[0]->sourceModule)->toBe('Shared');
});
