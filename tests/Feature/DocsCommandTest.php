<?php

it('writes the module documentation', function () {
    $output = sys_get_temp_dir().'/cordon-docs-'.uniqid();

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
