<?php

declare(strict_types=1);

namespace Cordon\Tests\Coverage;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;

/**
 * Many tests run Cordon the way a project does: bin/cordon, PHPStan with the
 * Cordon extension and the oracle scripts, each in a PHP process of its own.
 * Without this extension, the code those processes run counts as uncovered,
 * and a mutant is never applied there, so those tests cannot kill it.
 *
 * While the suite collects coverage with PCOV or runs a mutant, every PHP
 * process it starts, and the processes those start, reads one more ini file
 * through PHP_INI_SCAN_DIR. The file prepends prepend.php, which applies the
 * mutant and writes the coverage of the process to a directory. After each
 * test, that coverage is added to the test that started the processes.
 */
final class SubprocessCoverage implements Extension
{
    private const INI_SCAN_DIR = 'PHP_INI_SCAN_DIR';

    public const COVERAGE_DIRECTORY = 'CORDON_SUBPROCESS_COVERAGE';

    private static ?string $directory = null;

    private static ?string $coverageDirectory = null;

    private static string|false $originalScanDir = false;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements ExecutionStartedSubscriber
            {
                public function notify(ExecutionStarted $event): void
                {
                    SubprocessCoverage::start();
                }
            },
            new class implements FinishedSubscriber
            {
                public function notify(Finished $event): void
                {
                    SubprocessCoverage::collect($event->test()->id());
                }
            },
            new class implements ExecutionFinishedSubscriber
            {
                public function notify(ExecutionFinished $event): void
                {
                    SubprocessCoverage::finish();
                }
            },
        );
    }

    public static function start(): void
    {
        $coverage = CodeCoverage::instance()->isActive() && extension_loaded('pcov');
        $mutant = getenv('PEST_MUTATION_TESTING') !== false;

        // A mutant run inherits the environment of the run that collected the
        // coverage: never let it write coverage to that run's directory.
        self::setEnvironment(self::COVERAGE_DIRECTORY, false);

        if (! $coverage && ! $mutant) {
            return;
        }

        $directory = sys_get_temp_dir().'/cordon-subprocess-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);
        self::$directory = $directory;

        $ini = 'auto_prepend_file="'.__DIR__.'/prepend.php"'."\n";

        if ($coverage) {
            $source = (string) realpath(dirname(__DIR__, 2).'/src');
            $ini .= "pcov.enabled=1\npcov.directory=\"{$source}\"\n";
            self::$coverageDirectory = $directory.'/coverage';
            mkdir(self::$coverageDirectory);
            self::setEnvironment(self::COVERAGE_DIRECTORY, self::$coverageDirectory);
        }

        file_put_contents($directory.'/zz-cordon-subprocess.ini', $ini);

        // The empty element before the separator keeps PHP's own scan
        // directory, where the extensions (PCOV among them) are enabled.
        self::$originalScanDir = getenv(self::INI_SCAN_DIR);
        self::setEnvironment(self::INI_SCAN_DIR, (self::$originalScanDir === false ? '' : self::$originalScanDir).PATH_SEPARATOR.$directory);
    }

    public static function collect(string $test): void
    {
        if (self::$coverageDirectory === null) {
            return;
        }

        $files = glob(self::$coverageDirectory.'/*.json') ?: [];

        if ($files === []) {
            return;
        }

        $coverage = CodeCoverage::instance()->codeCoverage();
        // append() records the test with an unknown status: keep the one
        // PHPUnit recorded when the test itself stopped.
        $tests = $coverage->getTests();

        foreach ($files as $file) {
            $lines = json_decode((string) file_get_contents($file), true);
            unlink($file);

            if (is_array($lines) && $lines !== []) {
                $coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($lines), $test);
            }
        }

        if (isset($tests[$test])) {
            $coverage->setTests([$test => $tests[$test]] + $coverage->getTests());
        }
    }

    public static function finish(): void
    {
        if (self::$directory === null) {
            return;
        }

        self::setEnvironment(self::INI_SCAN_DIR, self::$originalScanDir);
        self::setEnvironment(self::COVERAGE_DIRECTORY, false);

        foreach (array_merge(glob(self::$directory.'/coverage/*') ?: [], glob(self::$directory.'/*.ini') ?: []) as $file) {
            unlink($file);
        }

        if (self::$coverageDirectory !== null) {
            rmdir(self::$coverageDirectory);
        }

        rmdir(self::$directory);
        self::$directory = null;
        self::$coverageDirectory = null;
    }

    /**
     * Symfony Process passes on only the variables that are also in $_SERVER,
     * and exec() and shell_exec() read the ones putenv() sets.
     */
    private static function setEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_SERVER[$name] = $value;
    }
}
