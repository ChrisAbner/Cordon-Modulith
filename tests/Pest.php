<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Oracle\Temp;
use Cordon\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function fixture_path(string $path = ''): string
{
    return rtrim(__DIR__.'/Fixtures/'.ltrim($path, '/'), '/');
}

/**
 * Analyse tests/Fixtures/namespace-app (modules Billing, Catalog, Shared).
 *
 * @param  array<string, mixed>  $moduleConfig
 * @param  array<array-key, mixed>  $rules
 * @param  array<string, bool>  $listeners
 */
function analyse_namespace_app(array $moduleConfig = ['Shared' => ['open' => true]], array $rules = [], array $listeners = []): Result
{
    $modules = (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))
        ->resolve()
        ->configure($moduleConfig);

    $built = RuleSet::fromConfig($rules, null, $listeners);

    $analyzer = new Analyzer(
        new PhpParserExtractor,
        new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']),
        new PublicApiPolicy,
        $built,
        RuleSet::severities($rules, $built),
    );

    return $analyzer->analyze($modules, fixture_path('namespace-app'));
}

/**
 * @return list<Violation>
 */
function violations_for(Result $result, string $rule): array
{
    return array_values(array_filter($result->violations, fn (Violation $violation) => $violation->rule === $rule));
}

/**
 * A copy of a fixture project in a temporary directory, so commands can write to it.
 */
function temp_project(?string $fixture = null): string
{
    $directory = Temp::directory('cordon-cli-');

    if ($fixture !== null) {
        $source = fixture_path($fixture);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file) {
            $target = $directory.substr($file->getPathname(), strlen($source));
            $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
        }
    }

    return $directory;
}

/**
 * A file of module Billing with a @cordon-ignore that has no reason.
 */
function add_unexplained_ignore(string $project): void
{
    file_put_contents($project.'/app/Modules/Billing/Unexplained.php', "<?php\n\nnamespace App\\Modules\\Billing;\n\n// @cordon-ignore internal_access\nnew \\App\\Modules\\Catalog\\Models\\Stock;\n");
}
