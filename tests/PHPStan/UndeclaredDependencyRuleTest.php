<?php

namespace Cordon\Tests\PHPStan;

use Cordon\PHPStan\CordonContext;
use Cordon\PHPStan\UndeclaredDependencyRule;
use Cordon\Tests\Oracle\Temp;
use FilesystemIterator;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @extends RuleTestCase<UndeclaredDependencyRule>
 */
final class UndeclaredDependencyRuleTest extends RuleTestCase
{
    private const APP = __DIR__.'/../Fixtures/namespace-app';

    private const LISTENER = '/app/Modules/Catalog/Listeners/UpdateStockOnInvoicePaid.php';

    private const MESSAGE = 'Module [Catalog] depends on module [Billing] (via App\Modules\Billing\Events\InvoicePaid), but [Billing] is not listed in its depends_on.';

    private const TIP = 'Add [Billing] to the depends_on of [Catalog] in config/cordon.php if the dependency is intended. Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#undeclared-dependency';

    private string $app = self::APP;

    protected function getRule(): Rule
    {
        return new UndeclaredDependencyRule(new CordonContext($this->createReflectionProvider(), (string) realpath($this->app)));
    }

    public function test_it_reports_a_dependency_missing_from_depends_on(): void
    {
        $this->app = $this->copyOfTheApp(['Catalog' => ['depends_on' => []]]);

        $this->analyse([$this->app.self::LISTENER], [[self::MESSAGE, 9, self::TIP]]);
    }

    public function test_it_honours_cordon_ignore_comments(): void
    {
        $this->app = $this->copyOfTheApp(['Catalog' => ['depends_on' => []]]);
        $lines = file($this->app.self::LISTENER, FILE_IGNORE_NEW_LINES) ?: [];
        $lines[8] .= ' // @cordon-ignore undeclared_dependency (stock follows paid invoices)';
        file_put_contents($this->app.self::LISTENER, implode("\n", $lines)."\n");

        $this->analyse([$this->app.self::LISTENER], []);
    }

    public function test_it_checks_only_modules_that_declare_depends_on(): void
    {
        $this->app = $this->copyOfTheApp([]);

        $this->analyse([$this->app.self::LISTENER], []);
    }

    public function test_it_leaves_listeners_out_when_configured(): void
    {
        $this->app = $this->copyOfTheApp(['Catalog' => ['depends_on' => []]], "'listeners' => ['depends_on' => false]");

        $this->analyse([$this->app.self::LISTENER], []);
    }

    public function test_it_reports_only_in_error(): void
    {
        $this->app = $this->copyOfTheApp(['Catalog' => ['depends_on' => []]], "'rules' => ['undeclared_dependency' => 'warn']");

        $this->analyse([$this->app.self::LISTENER], []);
    }

    public function test_it_applies_the_cordon_baseline(): void
    {
        $this->app = $this->copyOfTheApp(['Catalog' => ['depends_on' => []]]);
        file_put_contents($this->app.'/cordon-baseline.json', json_encode(['version' => 2, 'violations' => [[
            'rule' => 'undeclared_dependency',
            'file' => 'app/Modules/Catalog/Listeners/UpdateStockOnInvoicePaid.php',
            'target' => 'Billing',
            'count' => 1,
        ]]]));

        $this->analyse([$this->app.self::LISTENER], []);
    }

    /**
     * A copy of the namespace app with its own config/cordon.php.
     *
     * @param  array<string, array<string, mixed>>  $modules  Settings added to Shared => open.
     */
    private function copyOfTheApp(array $modules, string $extra = ''): string
    {
        $directory = Temp::removeAtExit(sys_get_temp_dir().'/cordon-phpstan-'.bin2hex(random_bytes(4)));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($directory);

        foreach ($files as $file) {
            $target = $directory.substr($file->getPathname(), strlen(self::APP));
            $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
        }

        $settings = var_export(['Shared' => ['open' => true], ...$modules], true);
        file_put_contents($directory.'/config/cordon.php', "<?php\n\nreturn ['modules' => {$settings}".($extra !== '' ? ", {$extra}" : '')."];\n");

        return $directory;
    }
}
