<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\IntelligencePanel;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;

/**
 * The Intelligence panel of Story 5.3 against the engine mock: a connection starts the three
 * imports, a link that is not taken is worded by the module, the state follows the health check,
 * the confirmation is saved for the panel's scope, and a disconnect ends the connection.
 */
final class IntelligencePanelTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::db()->delete(JobStore::TABLE, '`type` LIKE \'engine\\_%\'');
        \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(JobStore::TABLE, '`type` LIKE \'engine\\_%\'');
            \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
        }
        parent::tearDown();
    }

    public function testAConnectionShowsTheStoreAndQueuesTheThreeImports(): void
    {
        self::assertSame(['connected' => false, 'state' => '', 'consent' => false, 'imports' => null], array_intersect_key($this->panel()->values($this->scope()), ['connected' => 1, 'state' => 1, 'consent' => 1, 'imports' => 1]));

        $result = $this->panel()->connect($this->scope(), $this->engine->setupLink('panel1'));

        self::assertNull($result['error']);
        $values = $result['values'];
        self::assertSame([true, 'Mock Store', '1.0.0', IntelligencePanel::STATE_CONNECTED], [$values['connected'], $values['name'], $values['engine_version'], $values['state']]);
        self::assertSame(['catalog' => 'queued', 'customers' => 'queued', 'orders' => 'queued'], array_map(static fn (array $import): string => $import['status'], $values['imports']));
        self::assertStringNotContainsString('sk_', (string) json_encode($values), 'no key reaches the page');

        $held = $this->panel()->import($this->scope(), 'catalog', 'cancel');
        self::assertSame(['cancelled', 'cancelled', 'cancelled'], array_column($held['imports'], 'status'), '"Hold back the import" ends the whole sequence');
        $again = $this->panel()->import($this->scope(), 'orders', 'start');
        self::assertSame(['cancelled', 'cancelled', 'queued'], array_column($again['imports'], 'status'));
        self::assertNull($this->panel()->import($this->scope(), 'orders', 'delete'), 'an unknown action');
    }

    public function testALinkThatIsNotTakenIsWordedByTheModuleAndStoresNothing(): void
    {
        $this->engine->set('used_tokens', ['usedpanel']);
        $said = [];
        foreach (['', 'https://example.test/setup/abc', $this->engine->setupLink('notfoundpanel'), $this->engine->setupLink('usedpanel')] as $link) {
            $result = $this->panel()->connect($this->scope(), $link);
            self::assertNull($result['values']);
            $said[] = $result['error'];
        }
        $this->engine->set('smaily_subdomain', 'otheraccount');
        $this->storeAccount(0, 'demoshop');
        $said[] = $this->panel()->connect($this->scope(), $this->engine->setupLink('mismatchpanel'))['error'];

        self::assertSame([
            'Paste the setup URL or token from Smaily first.',
            'The setup URL must be an https address on intelligence.smaily.com.',
            'Setup token not recognised. Verify the URL is correct.',
            'This setup link has already been used. Ask Smaily for a new one.',
            'This setup link is for the Campaign Intelligence store of another Smaily account, so the connection was not saved. The link is now used up: ask Smaily for a new setup link for this store’s Smaily account.',
        ], $said);
        self::assertSame(0, self::connectionRows());
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . JobStore::TABLE . '` WHERE `type` LIKE \'engine\\_%\''), 'no import without a connection');
    }

    public function testTheStateFollowsTheCheckAndADisconnectEndsTheConnection(): void
    {
        $this->panel()->connect($this->scope(), $this->engine->setupLink('panel2'));

        $this->engine->set('tenant_inactive', true);
        $check = $this->panel()->check($this->scope());
        self::assertSame([IntelligencePanel::STATE_NOT_ACTIVE, 'Campaign Intelligence still reports that this account is not active.'], [$check['values']['state'], $check['message']]);

        $this->engine->set('tenant_inactive', false);
        $this->engine->fail('ping', 503);
        $check = $this->panel()->check($this->scope());
        self::assertSame([IntelligencePanel::STATE_NOT_ACTIVE, 'Could not reach Campaign Intelligence.'], [$check['values']['state'], $check['message']], 'no answer keeps what was known');

        $this->engine->set('fail', []);
        $check = $this->panel()->check($this->scope());
        self::assertSame([IntelligencePanel::STATE_CONNECTED, ''], [$check['values']['state'], $check['message']]);

        $this->engine->revokeKeys();
        self::assertSame(IntelligencePanel::STATE_KEY_REFUSED, $this->panel()->check($this->scope())['values']['state']);

        $values = $this->panel()->disconnect($this->scope());
        self::assertFalse($values['connected']);
        self::assertSame(0, self::connectionRows());
    }

    public function testTheConfirmationIsOffUntilSavedAndSavedForThePanelsScopeOnly(): void
    {
        self::assertFalse($this->panel()->values($this->scope())['consent']);

        self::assertSame([], $this->panel()->save($this->scope(), ['consent' => true]));
        self::assertTrue($this->panel()->values($this->scope())['consent']);
        self::assertTrue((new EngineSettings())->consentConfirmed(0));
        self::assertSame([], $this->panel()->save($this->scope(), []));
        self::assertFalse($this->panel()->values($this->scope())['consent']);

        // Separate stores with "All shops" selected: the page saves nothing, and says so.
        $nowhere = new SetupScope(true, true, true, 0, ['en' => 'English']);
        self::assertNull($this->panel()->values($nowhere));
        self::assertSame(['', 'These shops are set up as separate stores. Reload the page and set each shop up on its own.'], array_values($this->panel()->save($nowhere, ['consent' => true])[0]));
        self::assertNotNull($this->panel()->connect($nowhere, $this->engine->setupLink('panel3'))['error']);
        self::assertSame(0, self::connectionRows());
    }

    private function panel(): IntelligencePanel
    {
        return (new SetupPanels($this->services, \Context::getContext()->getTranslator()))->intelligence();
    }

    private function scope(): SetupScope
    {
        return new SetupScope(false, false, true, 0, ['en' => 'English']);
    }
}
