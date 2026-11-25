<?php

use Cordon\Tests\Oracle\Temp;
use Illuminate\Support\Facades\Artisan;

it('writes the module documentation', function () {
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-docs-'.uniqid());

    $this->artisan('cordon:docs', ['--output' => $output])
        ->expectsOutputToContain('Documented 3 modules')
        ->assertExitCode(0);

    expect($output.'/README.md')->toBeFile()
        ->and($output.'/events.md')->toBeFile()
        ->and($output.'/modules/Billing.md')->toBeFile()
        ->and((string) file_get_contents($output.'/modules/Billing.md'))->toContain('**internal_access**');

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
});

it('writes the C4 diagram next to the Markdown with --diagrams=c4', function () {
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-docs-c4-'.uniqid());

    $this->artisan('cordon:docs', ['--output' => $output, '--diagrams' => 'c4'])
        ->expectsOutputToContain('Documented 3 modules')
        ->assertExitCode(0);

    expect($output.'/architecture.puml')->toBeFile()
        ->and((string) file_get_contents($output.'/architecture.puml'))->toStartWith("@startuml\n!include <C4/C4_Component>\n")
        ->toContain('Component(m0, "Billing", "Module")')
        ->and($output.'/README.md')->toBeFile()
        ->and((string) file_get_contents($output.'/README.md'))->toContain('```mermaid');

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md'), $output.'/architecture.puml'] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
});

it('writes only Markdown with --diagrams=mermaid, the default', function (array $options) {
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-docs-mermaid-'.uniqid());

    $this->artisan('cordon:docs', ['--output' => $output, ...$options])->assertExitCode(0);

    expect($output.'/architecture.puml')->not->toBeFile();

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
})->with([[[]], [['--diagrams' => 'mermaid']]]);

it('rejects unknown diagram formats', function () {
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-docs-bad-'.uniqid());

    expect(Artisan::call('cordon:docs', ['--output' => $output, '--diagrams' => 'svg']))->toBe(2)
        ->and(Artisan::output())->toContain('Unknown diagrams [svg]. Use mermaid or c4.')
        ->and($output)->not->toBeDirectory();
});
