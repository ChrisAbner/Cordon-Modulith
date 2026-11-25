<?php

use Composer\InstalledVersions;
use Cordon\Analysis\AnalysisCache;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\CachedExtractor;
use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\Result;
use Cordon\Contracts\DependencyExtractor;
use Cordon\Reporters\JsonReporter;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

/**
 * An extractor that records the files it is asked for.
 */
function spy_extractor(bool $tables = false): DependencyExtractor
{
    return new class($tables) implements DependencyExtractor
    {
        /** @var list<string> */
        public array $calls = [];

        public function __construct(private readonly bool $tables) {}

        public function extract(string $file): FileAnalysis
        {
            $this->calls[] = $file;

            return (new PhpParserExtractor(tables: $this->tables))->extract($file);
        }
    };
}

/**
 * Analyse $project through a cache file, as cordon:verify does: the cache is saved afterwards.
 *
 * @param  list<string>  $hostPaths
 * @return array{0: Result, 1: CachedExtractor}
 */
function analyse_cached(string $project, string $cacheFile, DependencyExtractor $spy, ?string $fingerprint = null, array $hostPaths = []): array
{
    $extractor = new CachedExtractor($spy, new AnalysisCache($cacheFile, $project, $fingerprint ?? AnalysisCache::fingerprint()));
    $result = analyzer_with($extractor)->analyze(
        (new NamespaceResolver($project.'/app/Modules', 'App\\Modules'))->resolve()->configure(['Shared' => ['open' => true]]),
        $project,
        $hostPaths,
    );
    $extractor->save();

    return [$result, $extractor];
}

function analyzer_with(DependencyExtractor $extractor): Analyzer
{
    return new Analyzer($extractor, new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']), new PublicApiPolicy, RuleSet::fromConfig());
}

function analyse_uncached(string $project): Result
{
    return analyzer_with(new PhpParserExtractor)->analyze(
        (new NamespaceResolver($project.'/app/Modules', 'App\\Modules'))->resolve()->configure(['Shared' => ['open' => true]]),
        $project,
    );
}

function render(Result $result): string
{
    return (new JsonReporter)->render($result);
}

/**
 * @return list<string>
 */
function cached_files(string $cacheFile): array
{
    return array_keys(json_decode((string) file_get_contents($cacheFile), true)['files']);
}

it('extracts every file on a cold run and none on a warm one, with the same result', function (string $fixture, bool $ignores) {
    $project = temp_project($fixture);
    $cacheFile = $project.'/storage/cordon.json';

    [$cold] = analyse_cached($project, $cacheFile, $coldSpy = spy_extractor());
    [$warm] = analyse_cached($project, $cacheFile, $warmSpy = spy_extractor());

    // With @cordon-ignore comments, a warm run that lost them would report what the cold run ignored.
    expect($cold->ignored > 0)->toBe($ignores)
        ->and($coldSpy->calls)->not->toBeEmpty()
        ->and($warmSpy->calls)->toBe([])
        ->and($warm)->toEqual($cold)
        ->and(render($warm))->toBe(render($cold))
        ->and(render($warm))->toBe(render(analyse_uncached($project)))
        ->and(cached_files($cacheFile))->toHaveCount(count($coldSpy->calls));
})->with([
    'namespace-app' => ['namespace-app', false],
    'ignore-app' => ['ignore-app', true],
]);

it('keeps the file layout of a cached analysis: no absolute path is stored', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';

    analyse_cached($project, $cacheFile, spy_extractor());

    expect((string) file_get_contents($cacheFile))->not->toContain($project)
        ->and(cached_files($cacheFile))->toContain('app/Modules/Billing/Services/CheckoutService.php');
});

it('reuses the cache when the project moves to another directory', function () {
    $project = temp_project('namespace-app');
    $moved = temp_project();
    rename($project, $moved.'/app');
    $cacheFile = $moved.'/app/cordon.json';

    [$cold] = analyse_cached($moved.'/app', $cacheFile, spy_extractor());
    rename($moved.'/app', $moved.'/elsewhere');
    [$warm] = analyse_cached($moved.'/elsewhere', $moved.'/elsewhere/cordon.json', $spy = spy_extractor());

    expect($spy->calls)->toBe([])
        ->and(render($warm))->toBe(render(analyse_uncached($moved.'/elsewhere')))
        ->and($warm)->toEqual(analyse_uncached($moved.'/elsewhere'))
        ->and($cold->filesAnalysed)->toBe($warm->filesAnalysed);
});

it('extracts only the file that changed', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());

    $changed = $project.'/app/Modules/Billing/Services/CheckoutService.php';
    file_put_contents($changed, "\n// touched\n", FILE_APPEND);
    mkdir($project.'/app/Modules/Billing/Models');
    file_put_contents($project.'/app/Modules/Billing/Models/NewModel.php', "<?php\n\nnamespace App\\Modules\\Billing\\Models;\n\nnew \\App\\Modules\\Catalog\\Models\\Stock;\n");

    [$result] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($spy->calls)->toEqualCanonicalizing([$changed, $project.'/app/Modules/Billing/Models/NewModel.php'])
        ->and(render($result))->toBe(render(analyse_uncached($project)));
});

it('drops the entry of a deleted file and extracts nothing', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());
    $before = cached_files($cacheFile);

    unlink($project.'/app/Modules/Billing/Services/CheckoutService.php');
    [$result] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($spy->calls)->toBe([])
        ->and(cached_files($cacheFile))->toBe(array_values(array_diff($before, ['app/Modules/Billing/Services/CheckoutService.php'])))
        ->and(render($result))->toBe(render(analyse_uncached($project)));
});

it('extracts a renamed file once under its new name and forgets the old one', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());

    rename($project.'/app/Modules/Billing/Services/CheckoutService.php', $project.'/app/Modules/Billing/Services/Renamed.php');
    [$result] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($spy->calls)->toBe([$project.'/app/Modules/Billing/Services/Renamed.php'])
        ->and(cached_files($cacheFile))->toContain('app/Modules/Billing/Services/Renamed.php')
        ->and(cached_files($cacheFile))->not->toContain('app/Modules/Billing/Services/CheckoutService.php')
        ->and(render($result))->toBe(render(analyse_uncached($project)));
});

it('caches a file that does not parse', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    file_put_contents($project.'/app/Modules/Billing/Broken.php', "<?php\n\nclass {\n");

    [$cold] = analyse_cached($project, $cacheFile, spy_extractor());
    [$warm] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($cold->parseErrors)->toHaveCount(1)
        ->and($spy->calls)->toBe([])
        ->and($warm->parseErrors)->toBe($cold->parseErrors);
});

it('does not trust the cache after the fingerprint changes', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    [, $first] = analyse_cached($project, $cacheFile, $coldSpy = spy_extractor(), 'one');

    [$other] = analyse_cached($project, $cacheFile, $otherSpy = spy_extractor(), 'two');
    analyse_cached($project, $cacheFile, $sameSpy = spy_extractor(), 'two');

    expect($otherSpy->calls)->toHaveCount(count($coldSpy->calls))
        ->and($sameSpy->calls)->toBe([])
        ->and($first->warnings())->toBe([])
        ->and(render($other))->toBe(render(analyse_uncached($project)));
});

it('builds the fingerprint from the versions and the extractor options', function () {
    $versions = ['cordon' => '0.5.0', 'parser' => '5.3.0', 'phpdoc' => '2.0.1', 'php' => '8.4.1', 'support' => '12.0.0'];
    $base = AnalysisCache::fingerprint(['docblocks' => false], $versions);

    expect(AnalysisCache::fingerprint(['docblocks' => false], $versions))->toBe($base)
        ->and(AnalysisCache::fingerprint(['docblocks' => true], $versions))->not->toBe($base)
        ->and(AnalysisCache::fingerprint(['docblocks' => false], [...$versions, 'cordon' => '0.5.1']))->not->toBe($base)
        ->and(AnalysisCache::fingerprint(['docblocks' => false], [...$versions, 'parser' => '5.4.0']))->not->toBe($base)
        ->and(AnalysisCache::fingerprint(['docblocks' => false], [...$versions, 'php' => '8.5.0']))->not->toBe($base)
        ->and(AnalysisCache::fingerprint(['docblocks' => false], [...$versions, 'phpdoc' => '2.1.0']))->not->toBe($base)
        ->and(AnalysisCache::fingerprint(['docblocks' => false], [...$versions, 'support' => '12.1.0']))->not->toBe($base)
        ->and(AnalysisCache::fingerprint(['a' => 1, 'b' => 2], $versions))->toBe(AnalysisCache::fingerprint(['b' => 2, 'a' => 1], $versions))
        ->and(AnalysisCache::fingerprint())->toBe(AnalysisCache::fingerprint([], null));
});

it('puts the installed phpdoc-parser and illuminate/support in the fingerprint', function () {
    $installed = AnalysisCache::installedVersions();

    expect(array_keys($installed))->toEqualCanonicalizing(['cordon', 'parser', 'phpdoc', 'php', 'support'])
        ->and($installed['phpdoc'])->toContain((string) InstalledVersions::getReference('phpstan/phpdoc-parser'))
        ->and($installed['phpdoc'])->not->toBe('@')
        ->and($installed['support'])->not->toBe('@')
        ->and($installed['support'])->toContain((string) InstalledVersions::getPrettyVersion('laravel/framework'))
        ->and(AnalysisCache::fingerprint(['tables' => true]))->toBe(AnalysisCache::fingerprint(['tables' => true], $installed));
});

it('uses the version of the package that replaces a package without a version of its own', function () {
    // laravel/framework replaces illuminate/support: Composer records no version for the replaced one.
    expect(InstalledVersions::getPrettyVersion('illuminate/support'))->toBeNull()
        ->and(AnalysisCache::installedVersion('illuminate/support'))->not->toBe('@')->not->toBe('unknown')
        ->and(AnalysisCache::installedVersion('illuminate/support'))->toContain((string) InstalledVersions::getPrettyVersion('laravel/framework'))
        ->and(AnalysisCache::installedVersion('illuminate/support'))->not->toBe(AnalysisCache::installedVersion('nikic/php-parser'));
});

it('gives unknown to a package that is neither installed nor replaced', function () {
    expect(AnalysisCache::installedVersion('nobody/nothing'))->toBe('unknown');
});

it('analyses without the cache and warns when it cannot be used', function (string $case) {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/storage/cordon.json';
    $message = sprintf('Cordon cache at %s is unreadable; analysing without it.', $cacheFile);
    mkdir($project.'/storage');

    match ($case) {
        'corrupt' => file_put_contents($cacheFile, '{"version": 1, "fingerprint": "x", "files": {'),
        'not an object' => file_put_contents($cacheFile, '"just a string"'),
        'other version' => file_put_contents($cacheFile, json_encode(['version' => 2, 'fingerprint' => AnalysisCache::fingerprint(), 'files' => []])),
        'no files' => file_put_contents($cacheFile, json_encode(['version' => 1, 'fingerprint' => AnalysisCache::fingerprint()])),
        'directory' => mkdir($cacheFile),
        'unwritable' => (function () use ($project, &$cacheFile, &$message) {
            // A regular file where the directory should be: neither readable nor writable, as a read-only mount.
            file_put_contents($project.'/blocked', '');
            $cacheFile = $project.'/blocked/cordon.json';
            $message = sprintf('Cordon cache at %s is unreadable; analysing without it.', $cacheFile);
        })(),
    };

    [$result, $extractor] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($extractor->warnings())->toBe([$message])
        ->and($spy->calls)->not->toBeEmpty()
        ->and(render($result))->toBe(render(analyse_uncached($project)));
})->with(['corrupt', 'not an object', 'other version', 'no files', 'directory', 'unwritable']);

it('replaces an unreadable cache with a good one', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    file_put_contents($cacheFile, 'not json');

    analyse_cached($project, $cacheFile, spy_extractor());
    [, $extractor] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($spy->calls)->toBe([])
        ->and($extractor->warnings())->toBe([]);
});

it('extracts a file again when its entry is damaged', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());

    $data = json_decode((string) file_get_contents($cacheFile), true);
    $key = 'app/Modules/Billing/Services/CheckoutService.php';
    $data['files'][$key]['analysis']['references'] = [['target' => 12]];
    file_put_contents($cacheFile, json_encode($data));

    [$result, $extractor] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($spy->calls)->toBe([$project.'/'.$key])
        ->and($extractor->warnings())->toBe([])
        ->and(render($result))->toBe(render(analyse_uncached($project)));
});

it('extracts a file again when an entry names a kind of reference or table that does not exist', function (string $part, string $kind) {
    $project = temp_project('tables-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor(tables: true));

    $data = json_decode((string) file_get_contents($cacheFile), true);
    $damaged = [];

    foreach ($data['files'] as $key => $entry) {
        if ($entry['analysis'][$part] !== []) {
            $data['files'][$key]['analysis'][$part][0]['kind'] = $kind;
            $damaged[] = $project.'/'.$key;
        }
    }

    expect($damaged)->not->toBeEmpty();

    file_put_contents($cacheFile, json_encode($data));

    [$result, $extractor] = analyse_cached($project, $cacheFile, $spy = spy_extractor(tables: true));

    expect($spy->calls)->toEqualCanonicalizing($damaged)
        ->and($extractor->warnings())->toBe([])
        ->and(render($result))->toBe(render(analyse_uncached($project)));
})->with([
    'a reference' => ['references', 'bogus'],
    'a table' => ['tables', 'bogus'],
]);

it('extracts a file again when an ignore comment in its entry is damaged', function (string $field, mixed $value) {
    $project = temp_project('ignore-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());

    $data = json_decode((string) file_get_contents($cacheFile), true);
    $key = 'app/Modules/Billing/Invoice.php';

    expect($data['files'][$key]['analysis']['ignores'])->not->toBeEmpty();

    $data['files'][$key]['analysis']['ignores'][0][$field] = $value;
    file_put_contents($cacheFile, json_encode($data));

    [$result, $extractor] = analyse_cached($project, $cacheFile, $spy = spy_extractor());

    expect($spy->calls)->toBe([$project.'/'.$key])
        ->and($extractor->warnings())->toBe([])
        ->and(render($result))->toBe(render(analyse_uncached($project)));
})->with([
    'rules that are not a list' => ['rules', 'internal_access'],
    'a rule that is not a string' => ['rules', [12]],
    'lines that are not a list' => ['lines', ['first' => 11]],
    'a line that is not a number' => ['lines', ['11']],
]);

it('neither reads nor writes the cache when disabled', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());
    $before = (string) file_get_contents($cacheFile);
    touch($cacheFile, 1_000_000);

    $extractor = new CachedExtractor($spy = spy_extractor(), new AnalysisCache($cacheFile, $project, AnalysisCache::fingerprint()));
    $extractor->disable();
    $result = analyzer_with($extractor)->analyze(
        (new NamespaceResolver($project.'/app/Modules', 'App\\Modules'))->resolve()->configure(['Shared' => ['open' => true]]),
        $project,
    );
    $extractor->save();

    clearstatcache();

    expect($spy->calls)->not->toBeEmpty()
        ->and((string) file_get_contents($cacheFile))->toBe($before)
        ->and(filemtime($cacheFile))->toBe(1_000_000)
        ->and($extractor->warnings())->toBe([])
        ->and(render($result))->toBe(render(analyse_uncached($project)));
});

it('does not rewrite a cache that nothing changed', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/cordon.json';
    analyse_cached($project, $cacheFile, spy_extractor());
    touch($cacheFile, 1_000_000);

    analyse_cached($project, $cacheFile, spy_extractor());
    clearstatcache();

    expect(filemtime($cacheFile))->toBe(1_000_000);
});

it('does not rewrite a cache that nothing changed when host files are analysed too', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/storage/cordon.json';
    // Sorts before app/Modules/..., but the analyzer extracts the module files first.
    mkdir($project.'/app/Http');
    file_put_contents($project.'/app/Http/Controller.php', "<?php\n\nnamespace App\\Http;\n\nnew \\App\\Modules\\Billing\\Services\\CheckoutService;\n");
    analyse_cached($project, $cacheFile, spy_extractor(), hostPaths: [$project.'/app']);
    touch($cacheFile, 1_000_000);

    [, $extractor] = analyse_cached($project, $cacheFile, $spy = spy_extractor(), hostPaths: [$project.'/app']);
    clearstatcache();

    expect($spy->calls)->toBe([])
        ->and(cached_files($cacheFile))->toContain('app/Http/Controller.php')
        ->and(filemtime($cacheFile))->toBe(1_000_000)
        ->and($extractor->warnings())->toBe([]);
});

it('writes the cache atomically and leaves no temporary file', function () {
    $project = temp_project('namespace-app');
    $cacheFile = $project.'/storage/framework/cache/cordon.json';

    analyse_cached($project, $cacheFile, spy_extractor());

    expect(scandir(dirname($cacheFile)))->toBe(['.', '..', 'cordon.json'])
        ->and(json_decode((string) file_get_contents($cacheFile), true))
        ->toMatchArray(['version' => 1, 'fingerprint' => AnalysisCache::fingerprint()]);
});

it('round-trips every analysis of the fixture projects through the cache format', function (string $fixture, bool $docblocks, bool $tables, bool $ignores) {
    // docblocks and tables on: with the defaults the docblock references and the table uses are not extracted, so they would not be round-tripped.
    $extractor = new PhpParserExtractor(docblocks: true, tables: true);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixture_path($fixture), FilesystemIterator::SKIP_DOTS));
    $count = $docblockReferences = $tableUses = $ignoreComments = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $analysis = $extractor->extract($file->getPathname());
        $restored = FileAnalysis::fromArray(json_decode((string) json_encode($analysis->toArray()), true), $analysis->file);

        expect($restored)->toEqual($analysis);
        $count++;
        $docblockReferences += count(array_filter($analysis->references, static fn (Reference $reference): bool => $reference->kind === Reference::DOCBLOCK));
        $tableUses += count($analysis->tables);
        $ignoreComments += count($analysis->ignores);
    }

    expect($count)->toBeGreaterThan(0)
        ->and($docblockReferences > 0)->toBe($docblocks)
        ->and($tableUses > 0)->toBe($tables)
        ->and($ignoreComments > 0)->toBe($ignores);
})->with([
    'namespace-app' => ['namespace-app', false, false, false],
    'layered-app' => ['layered-app', false, false, false],
    'listeners-docblocks-app' => ['listeners-docblocks-app', true, false, false],
    'docblocks-app' => ['docblocks-app', true, false, false],
    'tables-app' => ['tables-app', false, true, false],
    'ignore-app' => ['ignore-app', false, false, true],
    'docblock-ignore-app' => ['docblock-ignore-app', true, false, true],
]);
