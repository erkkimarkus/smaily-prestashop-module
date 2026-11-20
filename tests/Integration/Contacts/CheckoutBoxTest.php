<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\CheckoutBox;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\CheckoutBoxSetting;
use Tests\Integration\IntegrationTestCase;

/**
 * Acceptance 3: the module's own newsletter box is on the customer form only where
 * `ps_emailsubscription` is not active and "Show a newsletter checkbox at checkout" is on.
 */
final class CheckoutBoxTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Configuration::deleteByName(CheckoutBoxSetting::KEY);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(CheckoutBoxSetting::KEY);
        }
        parent::tearDown();
    }

    public function testWithoutTheNewsletterBlockANewInstallShowsTheBox(): void
    {
        $fields = $this->box(false)->fields();

        self::assertIsArray($fields);
        self::assertCount(1, $fields);
        self::assertSame(['newsletter', 'checkbox'], [$fields[0]->getName(), $fields[0]->getType()]);
    }

    public function testTheSettingSwitchedOffShowsNoBox(): void
    {
        \Configuration::updateGlobalValue(CheckoutBoxSetting::KEY, '0');

        self::assertNull($this->box(false)->fields());
    }

    public function testAShopsOwnValueWins(): void
    {
        \Configuration::updateGlobalValue(CheckoutBoxSetting::KEY, '0');
        $idShop = (int) \Context::getContext()->shop->id;
        \Configuration::updateValue(CheckoutBoxSetting::KEY, '1', false, (int) \Shop::getGroupFromShop($idShop), $idShop);

        self::assertCount(1, (array) $this->box(false)->fields());
    }

    public function testWithTheNewsletterBlockActiveTheModuleAddsNoSecondBox(): void
    {
        \Configuration::updateGlobalValue(CheckoutBoxSetting::KEY, '1');

        self::assertNull($this->box(true)->fields());
    }

    /**
     * The demo shop has `ps_emailsubscription` active: the hook adds nothing.
     */
    public function testTheHookAddsNoBoxBesideTheActiveNewsletterBlock(): void
    {
        self::assertTrue((bool) \Module::isEnabled('ps_emailsubscription'), 'the demo shop runs the newsletter block');

        self::assertNull(self::module()->hookAdditionalCustomerFormFields(['fields' => []]));
    }

    private function box(bool $blockActive): CheckoutBox
    {
        return new CheckoutBox(new CheckoutBoxSetting(), static fn (): bool => $blockActive);
    }
}
