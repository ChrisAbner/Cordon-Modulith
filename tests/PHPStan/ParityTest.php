<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Violation;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
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
    $project = sys_get_temp_dir().'/cordon-parity-'.getmypid().'-'.bin2hex(random_bytes(4));
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixture_path($fixture), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir($project);

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
