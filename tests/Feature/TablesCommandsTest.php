<?php

/**
 * @param  list<string>  $arguments
 * @return array<string, mixed>
 */
function tables_run(array $arguments, string $project): array
{
    $run = run_cordon([...$arguments, '--format=json'], $project);

    return ['code' => $run['code'], 'err' => $run['err'], 'json' => json_decode($run['out'], true)];
}

it('warns about shared tables through the standalone CLI when the rule is on', function () {
    $run = tables_run(['verify'], temp_project('tables-app'));
    $found = array_map(fn (array $v) => implode(' ', [$v['rule'], $v['severity'], $v['source_module'], $v['target_module'], $v['target'], $v['file'].':'.$v['line']]), $run['json']['warnings']);

    sort($found);

    expect($run['code'])->toBe(0)
        ->and($found)->toBe([
            'tables warn Billing Catalog categories app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:16',
            'tables warn Billing Catalog products app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:15',
            'tables warn Billing Catalog products app/Modules/Billing/Services/InvoiceReport.php:18',
            'tables warn Billing Orders order_items app/Modules/Billing/Models/Receipt.php:9',
            'tables warn Billing Orders orders app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:13',
            'tables warn Billing Orders orders app/Modules/Billing/Database/Migrations/2024_02_02_000000_add_total_to_orders_table.php:11',
            'tables warn Billing Orders orders app/Modules/Billing/Services/InvoiceReport.php:17',
        ])
        ->and($run['json']['warnings'][0]['docs_url'])->toEndWith('/guide/rules#tables');
});

it('keeps a project that does not turn the rule on exactly as it was', function () {
    $project = temp_project('tables-app');
    unlink($project.'/config/cordon.php');

    $run = tables_run(['verify'], $project);

    expect($run['code'])->toBe(0)->and($run['json']['warnings'])->toBe([])->and($run['json']['violations'])->toBe([])->and($run['err'])->toBe('');
});

it('fails the build only when the rule is an error', function () {
    $project = temp_project('tables-app');
    file_put_contents($project.'/config/cordon.php', "<?php\n\nreturn ['rules' => ['tables' => 'error']];\n");

    expect(tables_run(['verify'], $project)['code'])->toBe(1);
});

it('writes the tables in the module cards of cordon docs', function () {
    $project = temp_project('tables-app');
    $run = run_cordon(['docs', '--output=docs-out'], $project);

    expect($run['code'])->toBe(0)
        ->and((string) file_get_contents($project.'/docs-out/modules/Orders.md'))->toContain("## Tables\n\n- Owns: `order_items`, `orders`, `tenants`");

    unlink($project.'/config/cordon.php');
    run_cordon(['docs', '--output=docs-plain'], $project);

    expect((string) file_get_contents($project.'/docs-plain/modules/Orders.md'))->not->toContain('## Tables');
});

it('invalidates the analysis cache when the rule is turned on', function () {
    $project = temp_project('tables-app');
    $cache = $project.'/cache.json';
    $config = fn (string $rules) => file_put_contents($project.'/config/cordon.php', "<?php\n\nreturn ['cache' => 'cache.json', 'rules' => {$rules}];\n");
    $fingerprint = fn (): string => json_decode((string) file_get_contents($cache), true)['fingerprint'];

    $config("['tables' => 'off']");
    $off = tables_run(['verify'], $project);
    $offFingerprint = $fingerprint();

    $config("['tables' => 'warn']");
    $on = tables_run(['verify'], $project);

    expect($off['json']['warnings'])->toBe([])
        ->and(count($on['json']['warnings']))->toBe(7)
        ->and($fingerprint())->not->toBe($offFingerprint);

    $warm = tables_run(['verify'], $project);

    expect($warm['json']['warnings'])->toBe($on['json']['warnings']);
});
