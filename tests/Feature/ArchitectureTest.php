<?php

use Cordon\Laravel\Architecture;
use Cordon\Tests\Oracle\Temp;
use Illuminate\Support\Facades\Artisan;

it('returns the Mermaid flowchart that cordon:graph prints', function () {
    Artisan::call('cordon:graph');

    expect(Architecture::graph())->toBe(Artisan::output())
        ->toStartWith('flowchart LR')
        ->toContain('linkStyle 0 stroke:#d33');
});

it('limits the graph to a module and its neighbours', function () {
    Artisan::call('cordon:graph', ['--module' => 'Shared']);

    expect(Architecture::graph('Shared'))->toBe(Artisan::output());
});

it('returns the files that cordon:docs writes', function () {
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-architecture-'.uniqid());
    Artisan::call('cordon:docs', ['--output' => $output]);

    $files = Architecture::docs();

    expect(array_keys($files))->toBe(['README.md', 'modules/Billing.md', 'modules/Catalog.md', 'modules/Shared.md', 'events.md']);

    foreach ($files as $name => $contents) {
        expect($contents)->toBe(file_get_contents($output.'/'.$name));
    }

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
});

it('documents the violations of a module without applying the baseline', function () {
    expect(Architecture::docs()['modules/Billing.md'])->toContain('**internal_access**');
});

it('rejects a module that does not exist', function () {
    expect(fn () => Architecture::graph('Nowhere'))->toThrow(InvalidArgumentException::class, 'Unknown module [Nowhere]');
});
