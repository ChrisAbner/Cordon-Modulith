<?php

use Cordon\Tests\Oracle\Temp;
use Symfony\Component\Process\Process;

/**
 * Identifiers of the Cordon errors of one PHPStan run, with bleedingEdge,
 * which reuses the errors of rules on collected data between runs.
 *
 * @return list<string>
 */
function bleeding_edge_run(string $project): array
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--error-format=json', '--no-progress'], $project);
    $process->setTimeout(180);
    $process->run();
    $identifiers = [];

    foreach (json_decode($process->getOutput(), true)['files'] ?? [] as $data) {
        foreach ($data['messages'] as $message) {
            if (str_starts_with($message['identifier'] ?? '', 'cordon.')) {
                $identifiers[] = $message['identifier'];
            }
        }
    }

    sort($identifiers);

    return array_values(array_unique($identifiers));
}

it('notices a file that appears in a module PHPStan does not analyse', function () {
    if (! interface_exists('PHPStan\Analyser\DependencyTracker')) {
        $this->markTestSkipped('This PHPStan version cannot track dependencies (2.3+).');
    }

    $project = Temp::removeAtExit(sys_get_temp_dir().'/cordon-cache-'.getmypid().'-'.bin2hex(random_bytes(4)));
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixture_path('namespace-app'), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir($project);

    foreach ($files as $file) {
        $target = $project.substr($file->getPathname(), strlen(fixture_path('namespace-app')));
        $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
    }

    // Shared goes away and Shipping starts empty: PHPStan analyses Billing and Catalog, the whole graph.
    $shared = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project.'/app/Modules/Shared', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($shared as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($project.'/app/Modules/Shared');
    mkdir($project.'/app/Modules/Shipping');
    file_put_contents($project.'/config/cordon.php', "<?php return ['modules' => [], 'listeners' => ['cycles' => true]];\n");
    file_put_contents($project.'/phpstan.neon', implode("\n", [
        'includes:',
        '    - '.dirname(__DIR__, 2).'/extension.neon',
        '    - phar://'.dirname(__DIR__, 2).'/vendor/phpstan/phpstan/phpstan.phar/conf/bleedingEdge.neon',
        'parameters:',
        '    level: 0',
        '    paths:',
        '        - app/Modules/Billing',
        '        - app/Modules/Catalog',
        '    tmpDir: '.$project.'/.phpstan',
        '    parallel:',
        '        maximumNumberOfProcesses: 1',
    ])."\n");

    expect(bleeding_edge_run($project))->toBe(['cordon.cycle', 'cordon.internalAccess']);

    file_put_contents($project.'/app/Modules/Shipping/Parcel.php', "<?php\n\nnamespace App\\Modules\\Shipping;\n\nfinal class Parcel {}\n");

    expect(bleeding_edge_run($project))->toBe(['cordon.incompleteGraph', 'cordon.internalAccess']);
});
