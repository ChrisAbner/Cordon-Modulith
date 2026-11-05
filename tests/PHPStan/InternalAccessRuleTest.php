<?php

namespace Cordon\Tests\PHPStan;

use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;
use Cordon\Analysis\ReferenceCollector;
use Cordon\PHPStan\InternalAccessRule;
use FilesystemIterator;
use PhpParser\NodeTraverser;
use PHPStan\Parser\Parser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @extends RuleTestCase<InternalAccessRule>
 */
final class InternalAccessRuleTest extends RuleTestCase
{
    private const APP = __DIR__.'/../Fixtures/namespace-app';

    private const TIP = 'Depend on its public API instead: a class in a public namespace such as Contracts, or one marked #[PublicApi].';

    private string $app = self::APP;

    protected function getRule(): Rule
    {
        return new InternalAccessRule($this->createReflectionProvider(), (string) realpath($this->app));
    }

    public function test_it_reports_internal_classes_of_other_modules(): void
    {
        $this->analyse([self::APP.'/app/Modules/Billing/Services/CheckoutService.php'], [
            ['Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog].', 13, self::TIP],
        ]);
    }

    public function test_it_honours_the_internal_attribute_through_static_reflection(): void
    {
        $this->analyse([self::APP.'/app/Modules/Billing/Services/InvoiceRenderer.php'], [
            ['Module [Billing] uses App\Modules\Catalog\Contracts\LegacyCatalog, which is internal to module [Catalog].', 10, self::TIP],
        ]);
    }

    public function test_it_accepts_public_api_and_skips_excluded_directories(): void
    {
        $this->analyse([
            self::APP.'/app/Modules/Catalog/Listeners/UpdateStockOnInvoicePaid.php',
            self::APP.'/app/Modules/Billing/tests/CheckoutServiceFixture.php',
        ], []);
    }

    public function test_it_reports_a_project_config_it_cannot_evaluate_once(): void
    {
        $this->app = __DIR__.'/../Fixtures/nwidart-unloadable';

        $file = $this->app.'/Modules/Blog/app/Post.php';

        $this->analyse([$file], [
            [
                'Cordon could not evaluate config/modules.php (Class "Nwidart\Modules\Commands\CommandsList" not found), so its settings were ignored and the defaults are used. Set the matching paths in config/cordon.php (for example resolvers.nwidart.path) so it does not depend on that file.',
                1,
            ],
        ]);
    }

    public function test_it_applies_the_cordon_baseline(): void
    {
        $this->app = $this->copyOfTheApp([
            'version' => 2,
            'violations' => [[
                'rule' => 'internal_access',
                'file' => 'app/Modules/Billing/Services/CheckoutService.php',
                'target' => 'App\\Modules\\Catalog\\Models\\Product',
                'count' => 1,
            ]],
        ]);

        $this->analyse([
            $this->app.'/app/Modules/Billing/Services/CheckoutService.php',
            $this->app.'/app/Modules/Billing/Services/InvoiceRenderer.php',
        ], [
            ['Module [Billing] uses App\Modules\Catalog\Contracts\LegacyCatalog, which is internal to module [Catalog].', 10, self::TIP],
        ]);
    }

    public function test_it_reports_a_baseline_it_cannot_read_once(): void
    {
        $this->app = $this->copyOfTheApp('{ not json');

        $this->analyse([$this->app.'/app/Modules/Billing/Services/CheckoutService.php'], [
            ['Cordon could not read the baseline cordon-baseline.json (Syntax error), so it is not applied. Fix the file or regenerate it with cordon:verify --generate-baseline.', 1],
            ['Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog].', 13, self::TIP],
        ]);
    }

    /**
     * The rule reads PHPStan's AST instead of parsing each file again, so both
     * must yield the same references for every fixture.
     */
    public function test_phpstan_ast_yields_the_same_references_as_the_extractor(): void
    {
        $parser = self::getContainer()->getService('currentPhpVersionRichParser');
        self::assertInstanceOf(Parser::class, $parser);

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Fixtures', FilesystemIterator::SKIP_DOTS));
        $compared = 0;

        foreach ($files as $file) {
            $path = $file->getPathname();
            $expected = (new PhpParserExtractor)->extract($path);

            if (! str_ends_with($path, '.php') || $expected->error !== null) {
                continue;
            }

            $collector = new ReferenceCollector($path);
            (new NodeTraverser($collector))->traverse($parser->parseFile($path));

            $line = static fn (Reference $r): string => $r->target.'@'.$r->line;
            self::assertSame(array_map($line, $expected->references), array_map($line, $collector->references()), $path);
            $compared++;
        }

        self::assertGreaterThan(50, $compared);
    }

    /**
     * A copy of the namespace app with a cordon-baseline.json, so tests can write to it.
     *
     * @param  array<string, mixed>|string  $baseline
     */
    private function copyOfTheApp(array|string $baseline): string
    {
        $directory = sys_get_temp_dir().'/cordon-phpstan-'.bin2hex(random_bytes(4));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($directory);

        foreach ($files as $file) {
            $target = $directory.substr($file->getPathname(), strlen(self::APP));
            $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
        }

        file_put_contents($directory.'/cordon-baseline.json', is_string($baseline) ? $baseline : json_encode($baseline));

        return $directory;
    }
}
