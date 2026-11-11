<?php

namespace Cordon\Tests\PHPStan;

use Cordon\PHPStan\CordonContext;
use Cordon\PHPStan\CycleRule;
use Cordon\PHPStan\ModuleEdgeCollector;
use Cordon\Tests\Oracle\Temp;
use FilesystemIterator;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @extends RuleTestCase<CycleRule>
 */
final class CycleRuleTest extends RuleTestCase
{
    private const APP = __DIR__.'/../Fixtures/namespace-app';

    private const TIP = 'Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#cycles';

    private string $app = self::APP;

    private ?CordonContext $context = null;

    protected function getRule(): Rule
    {
        return new CycleRule($this->context());
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        return [new ModuleEdgeCollector($this->context())];
    }

    public function test_it_reports_each_dependency_of_a_cycle_at_its_first_reference(): void
    {
        $this->useApp("'listeners' => ['cycles' => true]");

        $this->analyse($this->moduleFiles(), [
            ['Module Billing depends on Catalog, which closes the dependency cycle Billing -> Catalog -> Billing. Break it by inverting one dependency, for example with an event or a contract.', 11, self::TIP],
            ['Module Catalog depends on Billing, which closes the dependency cycle Catalog -> Billing -> Catalog. Break it by inverting one dependency, for example with an event or a contract.', 9, self::TIP],
        ]);
    }

    public function test_a_listener_does_not_close_a_cycle_by_default(): void
    {
        $this->useApp();

        $this->analyse($this->moduleFiles(), []);
    }

    public function test_it_says_when_some_modules_were_not_analysed(): void
    {
        $this->useApp("'listeners' => ['cycles' => true]");

        $billing = array_values(array_filter($this->moduleFiles(), fn (string $file) => str_contains($file, '/Billing/')));

        $this->analyse($billing, [
            ['Cordon can only check cycles when PHPStan analyses every module; [Catalog, Shared] were not analysed. Add their paths to paths in your PHPStan config.', 1, 'Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/phpstan#cycles'],
        ]);
    }

    public function test_it_applies_the_cordon_baseline_and_the_severity(): void
    {
        $this->useApp("'listeners' => ['cycles' => true]");
        file_put_contents($this->app.'/cordon-baseline.json', json_encode(['version' => 2, 'violations' => [
            ['rule' => 'cycles', 'file' => null, 'target' => 'Billing -> Catalog', 'count' => 1],
        ]]));

        $this->analyse($this->moduleFiles(), [
            ['Module Catalog depends on Billing, which closes the dependency cycle Catalog -> Billing -> Catalog. Break it by inverting one dependency, for example with an event or a contract.', 9, self::TIP],
        ]);

        $this->useApp("'listeners' => ['cycles' => true], 'rules' => ['cycles' => 'warn']");

        $this->analyse($this->moduleFiles(), []);
    }

    private function context(): CordonContext
    {
        return $this->context ??= new CordonContext($this->createReflectionProvider(), (string) realpath($this->app));
    }

    /**
     * A copy of the namespace app with its own config/cordon.php.
     */
    private function useApp(string $extra = ''): void
    {
        $directory = Temp::removeAtExit(sys_get_temp_dir().'/cordon-phpstan-'.bin2hex(random_bytes(4)));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($directory);

        foreach ($files as $file) {
            $target = $directory.substr($file->getPathname(), strlen(self::APP));
            $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
        }

        file_put_contents($directory.'/config/cordon.php', "<?php\n\nreturn ['modules' => ['Shared' => ['open' => true]]".($extra !== '' ? ", {$extra}" : '')."];\n");

        $this->app = $directory;
        $this->context = null;
    }

    /**
     * @return list<string>
     */
    private function moduleFiles(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->app.'/app/Modules', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/tests/')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
