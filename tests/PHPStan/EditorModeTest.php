<?php

use Symfony\Component\Process\Process;

/**
 * Runs PHPStan with Cordon's extension on a copy of the namespace app, as an
 * editor does: the edited buffer is a temporary file standing in for the real one.
 *
 * @param  list<string>  $arguments
 * @return list<string> "file:line:message" for each Cordon error
 */
function phpstan_on_namespace_app(array $arguments, ?Closure $prepare = null): array
{
    $project = sys_get_temp_dir().'/cordon-editor-'.getmypid().'-'.bin2hex(random_bytes(4));
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixture_path('namespace-app'), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir($project);

    foreach ($files as $file) {
        $target = $project.substr($file->getPathname(), strlen(fixture_path('namespace-app')));
        $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
    }

    file_put_contents($project.'/phpstan.neon', implode("\n", [
        'includes:',
        '    - '.dirname(__DIR__, 2).'/extension.neon',
        'parameters:',
        '    level: 0',
        '    paths:',
        '        - app',
        '    tmpDir: '.$project.'/.phpstan',
    ])."\n");

    if ($prepare !== null) {
        $prepare($project);
    }

    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--error-format=raw', '--no-progress', ...array_map(fn (string $argument) => str_replace('{project}', $project, $argument), $arguments)],
        $project,
    );
    $process->run();

    $errors = array_values(array_filter(explode("\n", $process->getOutput()), fn (string $line) => str_contains($line, 'Module [')));

    return array_map(fn (string $line) => str_replace($project.'/', '', $line), $errors);
}

// Editor mode (--tmp-file / --instead-of) arrived in PHPStan 2.1.17; the lowest
// dependencies install an older one.
beforeEach(function () {
    $help = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpstan', 'analyse', '--help']);
    $help->run();

    if (! str_contains($help->getOutput(), '--tmp-file')) {
        $this->markTestSkipped('This PHPStan version has no editor mode (--tmp-file).');
    }
});

it('reports the file being edited in editor mode, under its real path', function () {
    $edited = sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php';
    copy(fixture_path('namespace-app/app/Modules/Billing/Services/CheckoutService.php'), $edited);

    $errors = phpstan_on_namespace_app(['--tmp-file', $edited, '--instead-of', '{project}/app/Modules/Billing/Services/CheckoutService.php', 'app']);

    expect($errors)->toContain('app/Modules/Billing/Services/CheckoutService.php:13:Module [Billing] uses App\\Modules\\Catalog\\Models\\Product, which is internal to module [Catalog].');
});

it('skips an edited file under an excluded directory, such as a module test', function () {
    $edited = sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php';
    copy(fixture_path('namespace-app/app/Modules/Billing/tests/CheckoutServiceFixture.php'), $edited);

    $errors = phpstan_on_namespace_app(['--tmp-file', $edited, '--instead-of', '{project}/app/Modules/Billing/tests/CheckoutServiceFixture.php', 'app']);

    expect(array_filter($errors, fn (string $error) => str_contains($error, 'tests/')))->toBe([]);
});

it('applies the baseline to the file being edited', function () {
    $edited = sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php';
    copy(fixture_path('namespace-app/app/Modules/Billing/Services/CheckoutService.php'), $edited);

    $errors = phpstan_on_namespace_app(
        ['--tmp-file', $edited, '--instead-of', '{project}/app/Modules/Billing/Services/CheckoutService.php', 'app'],
        fn (string $project) => file_put_contents($project.'/cordon-baseline.json', json_encode(['version' => 2, 'violations' => [[
            'rule' => 'internal_access',
            'file' => 'app/Modules/Billing/Services/CheckoutService.php',
            'target' => 'App\\Modules\\Catalog\\Models\\Product',
            'count' => 1,
        ]]])),
    );

    expect(array_filter($errors, fn (string $error) => str_contains($error, 'CheckoutService.php')))->toBe([]);
});
