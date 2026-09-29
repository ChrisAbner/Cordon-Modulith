<?php

namespace Cordon\Tests\PHPStan;

use Cordon\PHPStan\InternalAccessRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<InternalAccessRule>
 */
final class InternalAccessRuleTest extends RuleTestCase
{
    private const APP = __DIR__.'/../Fixtures/namespace-app';

    private const TIP = 'Depend on its public API instead: a class in a public namespace such as Contracts, or one marked #[PublicApi].';

    protected function getRule(): Rule
    {
        return new InternalAccessRule($this->createReflectionProvider(), (string) realpath(self::APP));
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
}
