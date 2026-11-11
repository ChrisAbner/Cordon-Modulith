<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Violation;
use Cordon\Laravel\ResolverFactory;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Oracle\Temp;
use Illuminate\Config\Repository;
use Symfony\Component\Process\Process;

const PHPSTAN_IDENTIFIERS = [
    'cordon.internalAccess' => 'internal_access',
    'cordon.undeclaredDependency' => 'undeclared_dependency',
    'cordon.cycle' => 'cycles',
];

/**
 * A copy of a fixture app with the given module settings, every listener counted for cycles.
 *
 * @param  array<string, array<string, mixed>>  $modules
 */
function parity_project(string $fixture, array $modules): string
{
    $project = Temp::directory('cordon-parity-');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixture_path($fixture), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($files as $file) {
        $target = $project.substr($file->getPathname(), strlen(fixture_path($fixture)));
        $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
    }

    is_dir($project.'/config') || mkdir($project.'/config');
    file_put_contents($project.'/config/cordon.php', '<?php return '.var_export(['modules' => $modules, 'listeners' => ['cycles' => true]], true).';');
    file_put_contents($project.'/phpstan.neon', "includes:\n    - ".dirname(__DIR__, 2)."/extension.neon\nparameters:\n    level: 0\n    paths:\n        - app\n    tmpDir: {$project}/.phpstan\n    parallel:\n        maximumNumberOfProcesses: 1\n");

    return $project;
}

/**
 * "rule line message" for each Cordon error PHPStan reports, sorted.
 *
 * @return list<string>
 */
function phpstan_findings(string $project): array
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--error-format=json', '--no-progress'], $project);
    $process->setTimeout(180);
    $process->run();
    $report = json_decode($process->getOutput(), true);
    $findings = [];

    foreach ($report['files'] ?? [] as $file => $data) {
        foreach ($data['messages'] as $message) {
            if (isset(PHPSTAN_IDENTIFIERS[$message['identifier'] ?? ''])) {
                $findings[] = PHPSTAN_IDENTIFIERS[$message['identifier']].' '.str_replace($project.'/', '', $file).':'.$message['line'].' '.parity_target($message['message']);
            }
        }
    }

    sort($findings);

    return $findings;
}

/**
 * The same, from the analysis cordon:verify runs. Cycles have no file: they
 * are matched by the file and line of their first reference, as PHPStan reports them.
 *
 * @param  array<string, array<string, mixed>>  $modules
 * @return list<string>
 */
function verify_findings(string $project, array $modules): array
{
    $map = (new NamespaceResolver($project.'/app/Modules', 'App\\Modules'))->resolve()->configure($modules);
    $rules = RuleSet::fromConfig([], null, ['cycles' => true]);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']), new PublicApiPolicy, $rules);
    $snapshot = $analyzer->snapshot($map);

    $first = [];

    foreach ($snapshot->context->edges as $edge) {
        $location = [str_replace($project.'/', '', $edge->reference->sourceFile), $edge->reference->line];
        $key = $edge->from->name.' -> '.$edge->to->name;

        if (! isset($first[$key]) || $location < $first[$key]) {
            $first[$key] = $location;
        }
    }

    $findings = array_map(function (Violation $violation) use ($project, $first): string {
        [$file, $line] = $violation->file !== null
            ? [str_replace($project.'/', '', $violation->file), $violation->line]
            : $first[(string) $violation->target];

        return $violation->rule.' '.$file.':'.$line.' '.parity_target($violation->message);
    }, $analyzer->check($snapshot)->violations);

    sort($findings);

    return $findings;
}

/**
 * The target of a finding, as its baseline key has it, read from the message
 * (the class used, the module depended on, or the dependency of a cycle).
 */
function parity_target(string $message): string
{
    return match (true) {
        (bool) preg_match('/^Module \[\w+\] uses (\S+), which/', $message, $m) => $m[1],
        (bool) preg_match('/^Module \[\w+\] depends on module \[(\w+)\]/', $message, $m) => $m[1],
        (bool) preg_match('/^Module (\w+) depends on (\w+), which closes/', $message, $m) => $m[1].' -> '.$m[2],
        default => $message,
    };
}

it('reports in PHPStan what cordon:verify reports', function (string $fixture, array $modules) {
    $project = parity_project($fixture, $modules);

    expect(phpstan_findings($project))->toBe(verify_findings($project, $modules))->not->toBe([]);
})->with([
    'namespace app' => ['namespace-app', ['Shared' => ['open' => true], 'Catalog' => ['depends_on' => []]]],
    'listeners app' => ['listeners-app', ['Billing' => ['depends_on' => []]]],
]);

/**
 * A copy of a real-* project with its expected.json config written where
 * the PHPStan extension reads it, every listener counted for cycles.
 *
 * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} The copy, the cordon config and the Laravel config.
 */
function real_parity_project(string $directory): array
{
    $expected = json_decode((string) file_get_contents($directory.'/expected.json'), true, 512, JSON_THROW_ON_ERROR);
    $project = Temp::directory('cordon-parity-');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $target = $project.substr($file->getPathname(), strlen($directory));
        $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
    }

    $cordon = [...($expected['config'] ?? []), 'listeners' => ['cycles' => true]];
    is_dir($project.'/config') || mkdir($project.'/config');
    file_put_contents($project.'/config/cordon.php', '<?php return '.var_export($cordon, true).';');

    foreach ($expected['laravel'] ?? [] as $name => $values) {
        file_put_contents($project.'/config/'.$name.'.php', '<?php return '.var_export($values, true).';');
    }

    file_put_contents($project.'/phpstan.neon', "includes:\n    - ".dirname(__DIR__, 2)."/extension.neon\nparameters:\n    level: 0\n    paths:\n        - .\n    excludePaths:\n        - .phpstan\n        - config\n    tmpDir: {$project}/.phpstan\n    parallel:\n        maximumNumberOfProcesses: 1\n");

    $defaults = require dirname(__DIR__, 2).'/config/cordon.php';

    return [$project, array_replace($defaults, $cordon), $expected['laravel'] ?? []];
}

/**
 * cordon:verify's findings for a real project, in the format of phpstan_findings().
 *
 * @param  array<string, mixed>  $cordon
 * @param  array<string, mixed>  $laravel
 * @return list<string>
 */
function real_verify_findings(string $project, array $cordon, array $laravel): array
{
    $config = new Repository([...$laravel, 'cordon' => $cordon]);
    $map = ResolverFactory::make($config, $project)->resolve()->configure($cordon['modules']);
    $rules = RuleSet::fromConfig($cordon['rules'], null, $cordon['listeners']);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector($cordon['exclude']), new PublicApiPolicy($cordon['public_namespaces']), $rules, RuleSet::severities($cordon['rules'], $rules));
    $snapshot = $analyzer->snapshot($map);
    $first = [];

    foreach ($snapshot->context->edges as $edge) {
        $location = [str_replace($project.'/', '', $edge->reference->sourceFile), $edge->reference->line];
        $key = $edge->from->name.' -> '.$edge->to->name;

        if (! isset($first[$key]) || $location < $first[$key]) {
            $first[$key] = $location;
        }
    }

    $findings = array_map(function (Violation $violation) use ($project, $first): string {
        [$file, $line] = $violation->file !== null ? [str_replace($project.'/', '', $violation->file), $violation->line] : $first[(string) $violation->target];

        return $violation->rule.' '.$file.':'.$line.' '.parity_target($violation->message);
    }, $analyzer->check($snapshot)->violations);

    sort($findings);

    return $findings;
}

/**
 * The accepted differences between PHPStan and cordon:verify, with their reason.
 *
 * @return array<string, string> "project|finding" => reason
 */
function known_differences(): array
{
    $catalog = json_decode((string) file_get_contents(dirname(__DIR__).'/Oracle/known-differences.json'), true, 512, JSON_THROW_ON_ERROR);
    $known = [];

    foreach ($catalog['differences'] as $difference) {
        $known[$difference['project'].'|'.$difference['finding']] = $difference['reason'];
    }

    return $known;
}

it('reports in PHPStan what cordon:verify reports on the real projects, except the known differences', function (string $directory) {
    [$project, $cordon, $laravel] = real_parity_project($directory);
    $name = basename($directory);
    $phpstan = phpstan_findings($project);
    $verify = real_verify_findings($project, $cordon, $laravel);
    $known = array_filter(known_differences(), fn (string $key) => str_starts_with($key, $name.'|'), ARRAY_FILTER_USE_KEY);
    $differences = [
        ...array_map(fn (string $f) => $name.'|only phpstan: '.$f, array_values(array_diff($phpstan, $verify))),
        ...array_map(fn (string $f) => $name.'|only verify: '.$f, array_values(array_diff($verify, $phpstan))),
    ];

    // An empty PHPStan report would match an empty verify report: a project with reviewed violations must have findings.
    $reviewed = json_decode((string) file_get_contents($directory.'/expected.json'), true)['violations'] ?? [];

    expect($reviewed === [] || ($verify !== [] && $phpstan !== []))->toBeTrue()
        ->and(array_values(array_diff($differences, array_keys($known))))->toBe([], 'New differences between PHPStan and cordon:verify')
        ->and(array_values(array_diff(array_keys($known), $differences)))->toBe([], 'Stale entries in tests/Oracle/known-differences.json');
})->with(function () {
    $projects = [];

    foreach (glob(fixture_path('real-*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $projects[basename($directory)] = [$directory];
    }

    return $projects;
})->group('oracle');
