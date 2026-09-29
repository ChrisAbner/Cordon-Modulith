<?php

/**
 * Benchmark: generates a synthetic project and times the full analysis.
 *
 * Usage: php tests/Benchmark/run.php [files=1000] [modules=20] [max-seconds]
 * Exits with 1 when max-seconds is given and the analysis is slower.
 */

declare(strict_types=1);

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$files = max(1, (int) ($argv[1] ?? 1000));
$moduleCount = max(2, (int) ($argv[2] ?? 20));
$maxSeconds = isset($argv[3]) ? (float) $argv[3] : null;

$root = sys_get_temp_dir().'/cordon-benchmark-'.$files.'-'.$moduleCount;
generate($root, $files, $moduleCount);

$modules = (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve();
$analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig([]));

$start = hrtime(true);
$result = $analyzer->analyze($modules, $root);
$seconds = (hrtime(true) - $start) / 1e9;

printf(
    "%d files, %d modules, %d cross-module references, %d violations: %.2f s (%.2f ms/file, peak memory %.1f MB)\n",
    $result->filesAnalysed,
    count($modules),
    $result->crossModuleReferences,
    count($result->violations),
    $seconds,
    $seconds * 1000 / max(1, $result->filesAnalysed),
    memory_get_peak_usage(true) / 1048576,
);

if ($maxSeconds !== null && $seconds > $maxSeconds) {
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
