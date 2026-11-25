<?php

/**
 * Benchmark: generates a synthetic project and times the full analysis.
 *
 * Usage: php tests/Benchmark/run.php [files=1000] [modules=20] [max-seconds] [--cache] [--tables]
 * Exits with 1 when max-seconds is given and an analysis is slower.
 * With --cache it times three passes: without the analysis cache, with an
 * empty one (which writes it) and with the one the previous pass wrote.
 * With --tables the tables rule is on: the extractor reads the tables of each
 * file, to measure what that costs against a run without it.
 */

declare(strict_types=1);

use Cordon\Analysis\AnalysisCache;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\CachedExtractor;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Contracts\DependencyExtractor;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$withCache = in_array('--cache', $argv, true);
$tables = in_array('--tables', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => ! str_starts_with($arg, '--')));

$files = max(1, (int) ($args[0] ?? 1000));
$moduleCount = max(2, (int) ($args[1] ?? 20));
$maxSeconds = isset($args[2]) ? (float) $args[2] : null;

$root = sys_get_temp_dir().'/cordon-benchmark-'.$files.'-'.$moduleCount;
generate($root, $files, $moduleCount);

$modules = (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve();
$slowest = 0.0;

$pass = static function (string $label, DependencyExtractor $extractor) use ($modules, $root, $tables, &$slowest): void {
    $analyzer = new Analyzer($extractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig($tables ? ['tables' => 'warn'] : []));

    $start = hrtime(true);
    $result = $analyzer->analyze($modules, $root);

    if ($extractor instanceof CachedExtractor) {
        $extractor->save();
    }

    $seconds = (hrtime(true) - $start) / 1e9;
    $slowest = max($slowest, $seconds);

    printf(
        "%s%d files, %d modules, %d cross-module references, %d violations: %.2f s (%.2f ms/file, peak memory %.1f MB)\n",
        $label,
        $result->filesAnalysed,
        count($modules),
        $result->crossModuleReferences,
        count($result->violations),
        $seconds,
        $seconds * 1000 / max(1, $result->filesAnalysed),
        memory_get_peak_usage(true) / 1048576,
    );
};

$pass($withCache ? 'no cache:    ' : '', new PhpParserExtractor(tables: $tables));

if ($withCache) {
    $cacheFile = $root.'/cordon-cache.json';
    @unlink($cacheFile);

    $cached = static fn (): CachedExtractor => new CachedExtractor(new PhpParserExtractor(tables: $tables), new AnalysisCache($cacheFile, $root, AnalysisCache::fingerprint(['tables' => $tables])));

    $pass('cold cache:  ', $cached());
    $pass('warm cache:  ', $cached());
    printf("cache file: %.1f MB\n", filesize($cacheFile) / 1048576);
}

if ($maxSeconds !== null && $slowest > $maxSeconds) {
    fwrite(STDERR, sprintf("Slower than the %.1f s budget.\n", $maxSeconds));
    exit(1);
}

/**
 * Each file is a class of about 60 lines with references to its own module,
 * to other modules' contracts and, now and then, to another module's internals.
 */
function generate(string $root, int $files, int $moduleCount): void
{
    if (is_file($root.'/.complete')) {
        return;
    }

    $perModule = (int) ceil($files / $moduleCount);

    for ($m = 0; $m < $moduleCount; $m++) {
        $module = 'Module'.$m;
        @mkdir($root."/app/Modules/{$module}/Contracts", 0777, true);
        @mkdir($root."/app/Modules/{$module}/Services", 0777, true);

        for ($i = 0; $i < $perModule && ($m * $perModule + $i) < $files; $i++) {
            $isContract = $i % 5 === 0;
            $namespace = "App\\Modules\\{$module}\\".($isContract ? 'Contracts' : 'Services');
            $class = ($isContract ? 'Contract' : 'Service').$i;
            $other = 'Module'.(($m + 1 + $i) % $moduleCount);
            $contract = 'Contract'.(($i - $i % 5) % max(1, $perModule));
            $internal = $i % 17 === 0 ? "\\App\\Modules\\{$other}\\Services\\Service1" : "\\App\\Modules\\{$module}\\Services\\Service1";

            $methods = '';

            for ($k = 0; $k < 8; $k++) {
                $methods .= <<<PHP

                    public function method{$k}(\\App\\Modules\\{$other}\\Contracts\\{$contract} \$dependency, int \$value = {$k}): ?{$internal}
                    {
                        if (\$value instanceof \\Countable) {
                            return null;
                        }

                        \$items = array_map(static fn (int \$x): int => \$x * 2, range(0, \$value));

                        return count(\$items) > 100 ? new {$internal} : null;
                    }

                PHP;
            }

            $keyword = $isContract ? 'interface' : 'final class';
            $body = $isContract ? "    public function handle(): void;\n" : $methods;

            file_put_contents(
                $root."/app/Modules/{$module}/".($isContract ? 'Contracts' : 'Services')."/{$class}.php",
                "<?php\n\nnamespace {$namespace};\n\n{$keyword} {$class}\n{\n{$body}}\n",
            );
        }
    }

    touch($root.'/.complete');
}
