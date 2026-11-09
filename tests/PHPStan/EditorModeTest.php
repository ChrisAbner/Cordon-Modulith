<?php

use Cordon\Tests\Oracle\Temp;
use Symfony\Component\Process\Process;

/**
 * Runs PHPStan with Cordon's extension on a copy of the namespace app, as an
 * editor does: the edited buffer is a temporary file standing in for the real one.
 *
 * @param  list<string>  $arguments
 * @return list<string> "file:line:message" for each Cordon error (cordon.* identifiers)
 */
function phpstan_on_namespace_app(array $arguments, ?Closure $prepare = null): array
{
    $project = Temp::removeAtExit(sys_get_temp_dir().'/cordon-editor-'.getmypid().'-'.bin2hex(random_bytes(4)));
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
        // One process: parallel workers sometimes stall on a two-CPU container.
        '    parallel:',
        '        maximumNumberOfProcesses: 1',
    ])."\n");

    if ($prepare !== null) {
        $prepare($project);
    }

    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--error-format=json', '--no-progress', ...array_map(fn (string $argument) => str_replace('{project}', $project, $argument), $arguments)],
        $project,
    );
    $process->setTimeout(180);
    $process->run();

    $errors = [];

    foreach (json_decode($process->getOutput(), true)['files'] ?? [] as $file => $data) {
        foreach ($data['messages'] as $message) {
            if (str_starts_with($message['identifier'] ?? '', 'cordon.')) {
                $errors[] = str_replace($project.'/', '', $file).':'.$message['line'].':'.$message['message'];
            }
        }
    }

    return $errors;
}

/**
 * The real file of the namespace app copy, edited as a temporary buffer.
 *
 * @return list<string>
 */
function edit_in_editor(string $relative, ?Closure $prepare = null, ?string $content = null): array
{
    $edited = Temp::removeAtExit(sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php');

    return phpstan_on_namespace_app(['--tmp-file', $edited, '--instead-of', '{project}/'.$relative, 'app'], function (string $project) use ($relative, $prepare, $content, $edited): void {
        if ($prepare !== null) {
            $prepare($project);
        }

        file_put_contents($edited, $content ?? (string) file_get_contents($project.'/'.$relative));
    });
}

/**
 * @param  array<string, mixed>  $cordon
 */
function write_cordon_config(string $project, array $cordon): void
{
    file_put_contents($project.'/config/cordon.php', '<?php return '.var_export($cordon, true).';');
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
    $edited = Temp::removeAtExit(sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php');
    copy(fixture_path('namespace-app/app/Modules/Billing/Services/CheckoutService.php'), $edited);

    $errors = phpstan_on_namespace_app(['--tmp-file', $edited, '--instead-of', '{project}/app/Modules/Billing/Services/CheckoutService.php', 'app']);

    expect($errors)->toContain('app/Modules/Billing/Services/CheckoutService.php:13:Module [Billing] uses App\\Modules\\Catalog\\Models\\Product, which is internal to module [Catalog].');
});

it('skips an edited file under an excluded directory, such as a module test', function () {
    $edited = Temp::removeAtExit(sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php');
    copy(fixture_path('namespace-app/app/Modules/Billing/tests/CheckoutServiceFixture.php'), $edited);

    $errors = phpstan_on_namespace_app(['--tmp-file', $edited, '--instead-of', '{project}/app/Modules/Billing/tests/CheckoutServiceFixture.php', 'app']);

    expect(array_filter($errors, fn (string $error) => str_contains($error, 'tests/')))->toBe([]);
});

it('applies the baseline to the file being edited', function () {
    $edited = Temp::removeAtExit(sys_get_temp_dir().'/cordon-edited-'.bin2hex(random_bytes(4)).'.php');
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

it('skips an edited file under an excluded directory written in lower case', function () {
    $errors = edit_in_editor('app/Modules/Billing/database/factories/InvoiceFactory.php', function (string $project): void {
        write_cordon_config($project, ['modules' => ['Shared' => ['open' => true]], 'exclude' => ['vendor', 'node_modules', 'tests', 'Tests', 'database']]);
        mkdir($project.'/app/Modules/Billing/database/factories', 0777, true);
        file_put_contents($project.'/app/Modules/Billing/database/factories/InvoiceFactory.php', "<?php\n\nnamespace App\\Modules\\Billing\\Database\\Factories;\n\nuse App\\Modules\\Catalog\\Models\\Product;\n\nfinal class InvoiceFactory\n{\n    public function product(): Product\n    {\n        return new Product;\n    }\n}\n");
    });

    expect(array_filter($errors, fn (string $error) => str_contains($error, 'InvoiceFactory')))->toBe([]);
});

it('reports a file that declares no class, such as a routes file', function () {
    $errors = edit_in_editor('app/Modules/Billing/routes/web.php', function (string $project): void {
        mkdir($project.'/app/Modules/Billing/routes');
        file_put_contents($project.'/app/Modules/Billing/routes/web.php', "<?php\n\nuse App\\Modules\\Catalog\\Models\\Product;\n\nreturn Product::class;\n");
    });

    expect($errors)->toContain('app/Modules/Billing/routes/web.php:5:Module [Billing] uses App\\Modules\\Catalog\\Models\\Product, which is internal to module [Catalog].');
});

it('applies the baseline when another file of the module has the same name', function () {
    $errors = edit_in_editor('app/Modules/Billing/Services/CheckoutService.php', function (string $project): void {
        mkdir($project.'/app/Modules/Billing/Admin');
        file_put_contents($project.'/app/Modules/Billing/Admin/CheckoutService.php', "<?php\n\nnamespace App\\Modules\\Billing\\Admin;\n\nfinal class CheckoutService {}\n");
        file_put_contents($project.'/cordon-baseline.json', json_encode(['version' => 2, 'violations' => [[
            'rule' => 'internal_access',
            'file' => 'app/Modules/Billing/Services/CheckoutService.php',
            'target' => 'App\\Modules\\Catalog\\Models\\Product',
            'count' => 1,
        ]]]));
    });

    expect(array_filter($errors, fn (string $error) => str_contains($error, 'Services/CheckoutService.php')))->toBe([]);
});

it('reports undeclared dependencies and cycles in editor mode', function () {
    $errors = edit_in_editor('app/Modules/Catalog/Listeners/UpdateStockOnInvoicePaid.php', fn (string $project) => write_cordon_config($project, [
        'modules' => ['Shared' => ['open' => true], 'Catalog' => ['depends_on' => []]],
        'listeners' => ['cycles' => true],
    ]));

    expect($errors)->toContain('app/Modules/Catalog/Listeners/UpdateStockOnInvoicePaid.php:9:Module [Catalog] depends on module [Billing] (via App\\Modules\\Billing\\Events\\InvoicePaid), but [Billing] is not listed in its depends_on.')
        ->and(array_values(array_filter($errors, fn (string $error) => str_contains($error, 'closes the dependency cycle'))))->toHaveCount(2);
});
