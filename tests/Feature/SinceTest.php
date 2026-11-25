<?php

use Cordon\Support\GitChanges;
use Symfony\Component\Process\Process;

/**
 * Runs git in $directory with an identity of its own: the global git config is never read or written.
 *
 * @return string The standard output.
 */
function git_in(string $directory, string ...$arguments): string
{
    $process = new Process(['git', '-c', 'user.name=x', '-c', 'user.email=x@example.com', '-c', 'commit.gpgsign=false', ...$arguments], $directory);
    $process->mustRun();

    return $process->getOutput();
}

/**
 * A project in a git repository of its own, committed and tagged "base".
 */
function git_project(string $fixture = 'namespace-app'): string
{
    $project = temp_project($fixture);
    git_in($project, 'init', '-q');
    git_in($project, 'add', '-A');
    git_in($project, 'commit', '-q', '-m', 'base');
    git_in($project, 'tag', 'base');

    return $project;
}

function change(string $project, string $file): void
{
    file_put_contents($project.'/'.$file, "\n// changed\n", FILE_APPEND);
}

function commit_all(string $project): void
{
    git_in($project, 'add', '-A');
    git_in($project, 'commit', '-q', '-m', 'change');
}

/**
 * @return array<string, mixed>
 */
function affected_report(string $project, string ...$options): array
{
    $run = run_cordon(['affected', '--since=base', '--format=json', ...$options], $project);

    expect($run['code'])->toBe(0);

    return json_decode($run['out'], true);
}

/**
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function affected_names(array $report): array
{
    return array_column($report['affected'], 'module');
}

describe('GitChanges', function () {
    it('lists what changed since a reference: committed, staged, unstaged and untracked', function () {
        $project = git_project();
        change($project, 'app/Modules/Billing/Events/InvoicePaid.php');
        commit_all($project);
        change($project, 'app/Modules/Catalog/Models/Product.php');
        git_in($project, 'add', 'app/Modules/Catalog/Models/Product.php');
        change($project, 'app/Modules/Shared/Money.php');
        file_put_contents($project.'/app/Modules/Shared/New file.php', '<?php');

        expect(GitChanges::changedFiles($project, 'base'))->toBe([
            'app/Modules/Billing/Events/InvoicePaid.php',
            'app/Modules/Catalog/Models/Product.php',
            'app/Modules/Shared/Money.php',
            'app/Modules/Shared/New file.php',
        ]);
    });

    it('lists deleted files and both sides of a rename', function () {
        $project = git_project();
        unlink($project.'/app/Modules/Billing/Events/InvoicePaid.php');
        rename($project.'/app/Modules/Catalog/Models/Product.php', $project.'/app/Modules/Catalog/Models/Item.php');
        commit_all($project);

        expect(GitChanges::changedFiles($project, 'base'))->toBe([
            'app/Modules/Billing/Events/InvoicePaid.php',
            'app/Modules/Catalog/Models/Item.php',
            'app/Modules/Catalog/Models/Product.php',
        ]);
    });

    it('lists nothing when nothing changed, and only the files below a project in a subdirectory', function () {
        $project = git_project();

        expect(GitChanges::changedFiles($project, 'base'))->toBe([]);

        $repository = temp_project();
        mkdir($repository.'/backend');
        file_put_contents($repository.'/backend/a.php', '<?php');
        file_put_contents($repository.'/other.php', '<?php');
        git_in($repository, 'init', '-q');
        git_in($repository, 'add', '-A');
        git_in($repository, 'commit', '-q', '-m', 'base');
        file_put_contents($repository.'/backend/a.php', '<?php // changed');
        file_put_contents($repository.'/other.php', '<?php // changed');

        expect(GitChanges::changedFiles($repository.'/backend', 'HEAD'))->toBe(['a.php']);
    });

    it('fails with a message when there is no repository, no such reference, or the reference looks like an option', function (string $ref, bool $repository) {
        $project = $repository ? git_project() : temp_project('namespace-app');
        $marker = $project.'/injected';

        expect(fn () => GitChanges::changedFiles($project, $ref))
            ->toThrow(RuntimeException::class, sprintf('Cannot compare with [%s]: not a git repository or the reference does not exist.', $ref))
            ->and($marker)->not->toBeFile();
    })->with([
        'no repository' => ['base', false],
        'no such reference' => ['nope', true],
        'an empty reference' => ['', true],
        'an option' => ['--output=injected', true],
        'an option with a file' => ['-oinjected', true],
    ]);
});

describe('cordon affected', function () {
    it('lists the changed module and the ones that depend on it', function () {
        $project = git_project();
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');
        commit_all($project);

        $report = affected_report($project);

        expect($report['since'])->toBe('base')
            ->and($report['changed'])->toBe(['Catalog'])
            ->and($report['affected'])->toBe([
                ['module' => 'Catalog', 'reason' => 'changed', 'through' => null],
                ['module' => 'Billing', 'reason' => 'depends_on', 'through' => 'Catalog'],
            ]);
    });

    it('prints a text list that says why each module is affected', function () {
        $project = git_project();
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $run = run_cordon(['affected', '--since=base'], $project);

        expect($run['code'])->toBe(0)
            ->and($run['out'])->toContain('Modules affected by the changes since base:')
            ->toContain('Catalog  changed')
            ->toContain('Billing  depends on Catalog');
    });

    it('counts a deleted file, whatever the way it was changed, as a change of its module', function () {
        $project = git_project();
        unlink($project.'/app/Modules/Billing/Services/InvoiceRenderer.php');

        expect(affected_report($project)['changed'])->toBe(['Billing']);

        commit_all($project);

        expect(affected_report($project)['changed'])->toBe(['Billing']);
    });

    it('says so when nothing is affected, and ignores files outside the modules', function () {
        $project = git_project();

        $run = run_cordon(['affected', '--since=base'], $project);

        expect($run['code'])->toBe(0)->and($run['out'])->toContain('No module is affected by the changes since base.');

        file_put_contents($project.'/README.md', 'docs');

        expect(affected_report($project)['affected'])->toBe([]);
    });

    it('reports the code outside the modules as the host, which nothing depends on', function () {
        $project = git_project('host-app');
        change($project, 'app/Http/Controllers/OrderController.php');

        $report = affected_report($project);

        expect($report['affected'])->toBe([['module' => 'host', 'reason' => 'changed', 'through' => null]])
            ->and(run_cordon(['affected', '--since=base'], $project)['out'])->toContain('host  changed (the code outside the modules: no module depends on it)');
    });

    it('treats a change in a nested module as a change in its parent, which is what the outside sees', function () {
        $project = git_project('nested-app');
        change($project, 'app/Modules/Billing/Invoices/Services/InvoiceTotals.php');

        $report = affected_report($project);

        expect($report['changed'])->toBe(['Billing.Invoices'])
            ->and($report['affected'][0])->toBe(['module' => 'Billing.Invoices', 'reason' => 'changed', 'through' => null])
            ->and($report['affected'])->toContain(['module' => 'Billing', 'reason' => 'parent', 'through' => 'Billing.Invoices'])
            ->and($report['affected'])->toContain(['module' => 'Orders', 'reason' => 'depends_on', 'through' => 'Billing']);
    });

    it('treats a change in a parent as a change in its nested modules, which may use its internals', function () {
        $project = git_project('nested-app');
        change($project, 'app/Modules/Billing/Services/Money.php');

        $report = affected_report($project);

        expect($report['changed'])->toBe(['Billing'])
            ->and($report['affected'])->toContain(['module' => 'Billing.Invoices', 'reason' => 'nested', 'through' => 'Billing'])
            ->and($report['affected'])->toContain(['module' => 'Billing.Payments', 'reason' => 'nested', 'through' => 'Billing']);
    });

    it('starts the JSON report with its format version', function () {
        $project = git_project();
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $report = affected_report($project, '--tests');

        expect(array_keys($report))->toBe(['version', 'since', 'changed', 'affected', 'tests'])
            ->and($report['version'])->toBe(1)
            ->and($report['since'])->toBe('base');
    });

    it('lists the nested modules of a module that is affected only because it depends on a change', function () {
        $project = git_project('nested-app');
        file_put_contents($project.'/app/Modules/Billing/Services/OrderWatcher.php', "<?php\n\nnamespace App\\Modules\\Billing\\Services;\n\nuse App\\Modules\\Orders\\Contracts\\OrderPlaced;\n\nfinal class OrderWatcher\n{\n    public function watch(OrderPlaced \$event): void {}\n}\n");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'watcher');
        git_in($project, 'tag', '-f', 'base');
        change($project, 'app/Modules/Orders/Contracts/OrderPlaced.php');

        $report = affected_report($project, '--tests');

        expect($report['changed'])->toBe(['Orders'])
            ->and($report['affected'])->toBe([
                ['module' => 'Orders', 'reason' => 'changed', 'through' => null],
                ['module' => 'Billing', 'reason' => 'depends_on', 'through' => 'Orders'],
                ['module' => 'Billing.Invoices', 'reason' => 'nested', 'through' => 'Billing'],
                ['module' => 'Billing.Payments', 'reason' => 'nested', 'through' => 'Billing'],
            ]);
    });

    it('lists the parent and, with it, the siblings of a nested module that changed', function () {
        $project = git_project('nested-app');
        change($project, 'app/Modules/Billing/Payments/Models/Payment.php');

        $names = affected_names(affected_report($project));

        expect($names)->toContain('Billing.Payments', 'Billing', 'Orders')
            ->and($names)->toContain('Billing.Invoices');
    });

    it('gives the same list with the analysis cache, cold or warm, and without it', function () {
        $project = git_project();
        file_put_contents($project.'/config/cordon.php', "<?php return ['modules' => ['Shared' => ['open' => true]], 'cache' => 'storage/cordon.cache'];");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'cache');
        git_in($project, 'tag', '-f', 'base');
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $cold = run_cordon(['affected', '--since=base', '--format=json'], $project);

        expect($project.'/storage/cordon.cache')->toBeFile();

        $warm = run_cordon(['affected', '--since=base', '--format=json'], $project);
        unlink($project.'/config/cordon.php');
        file_put_contents($project.'/config/cordon.php', "<?php return ['modules' => ['Shared' => ['open' => true]]];");
        $none = run_cordon(['affected', '--since=base', '--format=json'], $project);

        expect($cold['code'])->toBe(0)
            ->and($warm['out'])->toBe($cold['out'])
            ->and($none['out'])->toBe($cold['out']);
    });

    it('fails with exit code 2 when the reference does not exist or there is no git repository', function (bool $repository, string $since) {
        $project = $repository ? git_project() : temp_project('namespace-app');
        $run = run_cordon(['affected', '--since='.$since, '--format=json'], $project);

        expect($run['code'])->toBe(2)
            ->and($run['out'])->toBe('')
            ->and($run['err'])->toContain(sprintf('Cannot compare with [%s]: not a git repository or the reference does not exist.', $since));
    })->with([
        'no repository' => [false, 'main'],
        'a missing reference' => [true, 'nope'],
        'an option' => [true, '--output=injected'],
    ]);

    it('fails with exit code 2 when there is no module, since it would select no test', function () {
        $project = git_project();
        file_put_contents($project.'/config/cordon.php', "<?php return ['resolver' => 'namespace', 'resolvers' => ['namespace' => ['path' => 'app/Nowhere', 'namespace' => 'App\\\\Nowhere']]];");

        $run = run_cordon(['affected', '--since=base'], $project);

        expect($run['code'])->toBe(2)
            ->and($run['out'])->toBe('')
            ->and($run['err'])->toContain('No modules found. Check the resolver settings in config/cordon.php (vendor/bin/cordon modules).');
    });

    it('warns that a file that does not parse may hide modules that depend on the changes', function () {
        $project = git_project();
        file_put_contents($project.'/app/Modules/Billing/Broken.php', "<?php\n\nnamespace App\\Modules\\Billing;\n\nclass Broken {\n");
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $run = run_cordon(['affected', '--since=base', '--format=json'], $project);

        expect($run['code'])->toBe(0)
            ->and(json_decode($run['out'], true)['changed'])->toContain('Catalog')
            ->and($run['err'])->toContain('1 file(s) could not be parsed, so their references are missing: some modules that depend on the changes may not be listed.');
    });

    it('passes on the warnings of an analysis cache it cannot use', function () {
        $project = git_project();
        mkdir($project.'/storage/cordon.json', 0777, true);
        file_put_contents($project.'/config/cordon.php', "<?php return ['cache' => 'storage/cordon.json', 'modules' => ['Shared' => ['open' => true]]];");
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $run = run_cordon(['affected', '--since=base', '--format=json'], $project);

        expect($run['code'])->toBe(0)
            ->and(json_decode($run['out'], true)['changed'])->toBe(['Catalog'])
            ->and($run['err'])->toContain('Cordon cache at')->toContain('is unreadable; analysing without it.');
    });

    it('needs --since', function () {
        $run = run_cordon(['affected'], git_project());

        expect($run['code'])->toBe(2)->and($run['err'])->toContain('The --since option is required');
    });

    it('rejects an unknown format', function () {
        $run = run_cordon(['affected', '--since=base', '--format=xml'], git_project());

        expect($run['code'])->toBe(2)->and($run['err'])->toContain('Unknown format [xml]. Use text or json.');
    });

    it('is registered in artisan too', function () {
        expect(Artisan::all())->toHaveKey('cordon:affected');
    });
});

describe('cordon affected --tests', function () {
    it('lists the test folders of the affected modules by convention, one per line', function () {
        $project = git_project();
        mkdir($project.'/tests/Catalog', 0777, true);
        mkdir($project.'/tests/Billing', 0777, true);
        mkdir($project.'/tests/Shared', 0777, true);
        file_put_contents($project.'/tests/Catalog/ProductTest.php', '<?php');
        file_put_contents($project.'/tests/Billing/CheckoutTest.php', '<?php');
        file_put_contents($project.'/tests/Shared/MoneyTest.php', '<?php');
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'tests');
        git_in($project, 'tag', '-f', 'base');
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $run = run_cordon(['affected', '--since=base', '--tests'], $project);

        // The tests folder inside the Billing module exists too: <module>/tests.
        expect($run['code'])->toBe(0)
            ->and($run['out'])->toBe("tests/Catalog\ntests/Billing\napp/Modules/Billing/tests\n")
            ->and(affected_report($project, '--tests')['tests'])->toBe(['tests/Catalog', 'tests/Billing', 'app/Modules/Billing/tests']);
    });

    it('lists the folders a module configures instead of the convention', function () {
        $project = git_project();
        mkdir($project.'/tests/Catalog', 0777, true);
        mkdir($project.'/tests/Unit/Pricing', 0777, true);
        file_put_contents($project.'/tests/Catalog/ProductTest.php', '<?php');
        file_put_contents($project.'/tests/Unit/Pricing/PriceTest.php', '<?php');
        file_put_contents($project.'/config/cordon.php', "<?php return ['modules' => ['Shared' => ['open' => true], 'Catalog' => ['tests' => ['tests/Unit/Pricing', 'tests/Gone']], 'Billing' => ['tests' => []]]];");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'tests');
        git_in($project, 'tag', '-f', 'base');
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $run = run_cordon(['affected', '--since=base', '--tests'], $project);

        expect($run['code'])->toBe(0)
            ->and($run['out'])->toBe("tests/Unit/Pricing\n")
            ->and($run['err'])->toContain('Module [Catalog]: the tests folder [tests/Gone] does not exist.');
    });

    it('leaves out a folder inside another listed one, so that Pest does not run its tests twice', function () {
        $project = git_project();
        mkdir($project.'/tests/Catalog/Unit', 0777, true);
        file_put_contents($project.'/tests/Catalog/Unit/ProductTest.php', '<?php');
        file_put_contents($project.'/config/cordon.php', "<?php return ['modules' => ['Shared' => ['open' => true], 'Catalog' => ['tests' => ['tests/Catalog/Unit', 'tests/Catalog']], 'Billing' => ['tests' => []]]];");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'tests');
        git_in($project, 'tag', '-f', 'base');
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        $run = run_cordon(['affected', '--since=base', '--tests'], $project);

        expect($run['code'])->toBe(0)->and($run['out'])->toBe("tests/Catalog\n");
    });

    it('prints nothing when the affected modules have no test folder', function () {
        $project = git_project('host-app');
        change($project, 'app/Modules/Catalog/Models/Product.php');

        $run = run_cordon(['affected', '--since=base', '--tests'], $project);

        expect($run['code'])->toBe(0)->and($run['out'])->toBe('');
    });

    it('counts a changed test as a change of the module it tests, and lists the host tests it is given', function () {
        $project = git_project('host-app');
        mkdir($project.'/tests/Feature', 0777, true);
        mkdir($project.'/tests/Catalog', 0777, true);
        file_put_contents($project.'/tests/Feature/HomeTest.php', '<?php');
        file_put_contents($project.'/tests/Catalog/ProductTest.php', '<?php');
        mkdir($project.'/config');
        file_put_contents($project.'/config/cordon.php', "<?php return ['host' => ['tests' => ['tests/Feature']]];");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'tests');
        git_in($project, 'tag', '-f', 'base');
        change($project, 'tests/Catalog/ProductTest.php');
        change($project, 'app/Http/Controllers/OrderController.php');

        $report = affected_report($project, '--tests');

        expect($report['changed'])->toBe(['Catalog', 'host'])
            ->and($report['tests'])->toContain('tests/Catalog', 'tests/Feature');
    });

    it('rejects --tests with a malformed tests setting', function () {
        $project = git_project();
        file_put_contents($project.'/config/cordon.php', "<?php return ['modules' => ['Catalog' => ['tests' => 'tests/Catalog']]];");

        $run = run_cordon(['affected', '--since=base', '--tests'], $project);

        expect($run['code'])->toBe(2)->and($run['err'])->toContain('Module [Catalog]: tests must be a list of directories, relative to the project root.');
    });
});

describe('cordon verify --since', function () {
    it('reports only the violations of the files that changed', function () {
        $project = git_project();
        change($project, 'app/Modules/Billing/Services/CheckoutService.php');
        commit_all($project);

        $run = run_cordon(['verify', '--since=base', '--format=json'], $project);

        expect($run['code'])->toBe(1)
            ->and(array_column(json_decode($run['out'], true)['violations'], 'file'))->toBe(['app/Modules/Billing/Services/CheckoutService.php']);
    });

    it('passes when the changed files have no violation, though others do', function () {
        $project = git_project();
        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        expect(run_cordon(['verify'], $project)['code'])->toBe(1)
            ->and(run_cordon(['verify', '--since=base'], $project)['code'])->toBe(0);
    });

    it('passes when nothing changed', function () {
        $project = git_project();

        expect(run_cordon(['verify', '--since=base'], $project)['code'])->toBe(0);
    });

    it('equals verifying the changed files, with the cache or without it', function () {
        $project = git_project();
        change($project, 'app/Modules/Billing/Services/InvoiceRenderer.php');
        file_put_contents($project.'/notes.txt', 'not php');

        $since = run_cordon(['verify', '--since=base', '--format=json'], $project);
        $paths = run_cordon(['verify', 'app/Modules/Billing/Services/InvoiceRenderer.php', '--format=json'], $project);
        $uncached = run_cordon(['verify', '--since=base', '--format=json', '--no-cache'], $project);

        expect($since['out'])->toBe($paths['out'])->and($uncached['out'])->toBe($since['out']);
    });

    it('still finds the cycles on the whole graph, and keeps the ones through a changed module', function () {
        $project = git_project();
        file_put_contents($project.'/app/Modules/Catalog/Support/CallsBilling.php', "<?php\n\nnamespace App\\Modules\\Catalog\\Support;\n\nuse App\\Modules\\Billing\\Events\\InvoicePaid;\n\nfinal class CallsBilling\n{\n    public function make(): object\n    {\n        return new InvoicePaid(1);\n    }\n}\n");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'cycle');
        git_in($project, 'tag', '-f', 'base');

        $rules = static fn (array $run): array => array_values(array_unique(array_column(json_decode($run['out'], true)['violations'], 'rule')));

        // The cycle Billing -> Catalog -> Billing is in the base commit: only a change in one of its modules brings it up.
        expect($rules(run_cordon(['verify', '--format=json'], $project)))->toContain('cycles');

        change($project, 'app/Modules/Shared/Money.php');

        expect($rules(run_cordon(['verify', '--since=base', '--format=json'], $project)))->toBe([]);

        change($project, 'app/Modules/Catalog/Support/PriceFormatter.php');

        expect($rules(run_cordon(['verify', '--since=base', '--format=json'], $project)))->toBe(['cycles']);
    });

    it('brings up the cycle through the module of a deleted file', function () {
        $project = git_project();
        file_put_contents($project.'/app/Modules/Catalog/Support/CallsBilling.php', "<?php\n\nnamespace App\\Modules\\Catalog\\Support;\n\nuse App\\Modules\\Billing\\Events\\InvoicePaid;\n\nfinal class CallsBilling\n{\n    public function make(): object\n    {\n        return new InvoicePaid(1);\n    }\n}\n");
        file_put_contents($project.'/app/Modules/Catalog/Support/Spare.php', "<?php\n\nnamespace App\\Modules\\Catalog\\Support;\n\nfinal class Spare {}\n");
        git_in($project, 'add', '-A');
        git_in($project, 'commit', '-q', '-m', 'cycle');
        git_in($project, 'tag', '-f', 'base');

        $rules = static fn (array $run): array => array_values(array_unique(array_column(json_decode($run['out'], true)['violations'], 'rule')));

        // Nothing changed in Catalog yet: the cycle in the base commit stays out.
        expect($rules(run_cordon(['verify', '--since=base', '--format=json'], $project)))->toBe([]);

        // The file is gone from the disk, so it has no real path: its directory still says it was Catalog's.
        unlink($project.'/app/Modules/Catalog/Support/Spare.php');

        expect($rules(run_cordon(['verify', '--since=base', '--format=json'], $project)))->toBe(['cycles']);
    });

    it('fails with exit code 2 and the message when the reference does not exist or there is no repository', function (bool $repository, string $since) {
        $project = $repository ? git_project() : temp_project('namespace-app');
        $run = run_cordon(['verify', '--since='.$since], $project);

        expect($run['code'])->toBe(2)
            ->and($run['out'])->toBe('')
            ->and($run['err'])->toContain(sprintf('Cannot compare with [%s]: not a git repository or the reference does not exist.', $since));
    })->with([
        'no repository' => [false, 'main'],
        'a missing reference' => [true, 'nope'],
        'an option' => [true, '-p'],
    ]);

    it('cannot be combined with paths or with the baseline commands', function (array $arguments, string $message) {
        $run = run_cordon(['verify', '--since=base', ...$arguments], git_project());

        expect($run['code'])->toBe(2)->and($run['err'])->toContain($message);
    })->with([
        'paths' => [['app/Modules/Billing'], 'The --since option cannot be combined with paths: --since is the list of changed files.'],
        'generate' => [['--generate-baseline'], 'The --since option cannot be combined with --generate-baseline or --prune-baseline: the baseline must cover every module.'],
        'prune' => [['--prune-baseline'], 'The --since option cannot be combined with --generate-baseline or --prune-baseline: the baseline must cover every module.'],
    ]);
});
