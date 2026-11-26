<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsScope;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\ShopOverrides;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\TabSaver;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * Linear CON-433 (owner, 2026-11-26): Settings > Connection saves no account that Smaily refuses,
 * and a workflow belongs to the Smaily account it was picked from. After a save that puts another
 * Smaily account (another subdomain) in the stored one's place, or that changes the language mode,
 * the workflows that the account does not list are removed and named on the Automations panel
 * until a workflow is picked again; another API user of the same subdomain removes nothing.
 *
 * One shop, the values all shops share. The Smaily mock lists the workflows 42 and 43 to every
 * account; 99 is a workflow of another account.
 */
final class LostWorkflowsTest extends IntegrationTestCase
{
    private const USERNAME = 'api-user';

    private const PASSWORD = 'not-a-real-password';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const MAPPING_TABLE = 'smaily_connect_automation_mapping';

    private const KEYS = [
        StoreLayout::LANGUAGE_MODE,
        StoreLayout::FALLBACK_LANGUAGE,
        AutomationSettings::WELCOME_ENABLED,
        AutomationSettings::FIRST_ORDER_ENABLED,
        AutomationSettings::CART_ENABLED,
        AutomationSettings::CART_DELAY_MINUTES,
        AutomationSettings::WELCOME_DISCOUNT_ENABLED,
        AutomationSettings::WELCOME_DISCOUNT_RULE,
        AutomationSettings::WELCOME_DISCOUNT_DAYS,
        AutomationSettings::FIRST_ORDER_DISCOUNT_ENABLED,
        AutomationSettings::FIRST_ORDER_DISCOUNT_RULE,
        AutomationSettings::FIRST_ORDER_DISCOUNT_DAYS,
        AutomationSettings::CART_DISCOUNT_ENABLED,
        AutomationSettings::CART_DISCOUNT_RULE,
        AutomationSettings::CART_DISCOUNT_DAYS,
        AutomationSettings::LOST_WORKFLOWS,
    ];

    private Services $services;

    private AccountStore $accounts;

    private MappingRepository $mappings;

    /** @var array<string, list<array<string, mixed>>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $mock = SmailyMockServer::start();
        $mock->acceptCredentials(self::USERNAME, self::PASSWORD);
        $mock->setWorkflows(array_map(
            static fn (int $id): array => ['id' => $id, 'title' => 'Workflow ' . $id, 'trigger_type' => 'form_submitted', 'is_enabled' => true],
            [42, 43]
        ));
        $transport = $mock->transport();
        $this->services = Services::fromProviders(Services::PROVIDERS);
        $this->services->set(Transport::class, static fn (): Transport => $transport);
        $this->accounts = $this->services->get(AccountStore::class);
        $this->mappings = $this->services->get(MappingRepository::class);

        foreach ([self::ACCOUNT_TABLE, self::MAPPING_TABLE] as $table) {
            $this->saved[$table] = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '`', true, false) ?: [];
            self::db()->delete($table);
        }
        foreach (self::KEYS as $key) {
            $this->saved[$key] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'');
            \Configuration::deleteByName($key);
        }
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach ([self::ACCOUNT_TABLE, self::MAPPING_TABLE] as $table) {
                self::db()->delete($table);
                foreach ($this->saved[$table] as $row) {
                    self::db()->execute('INSERT INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
                        . implode(', ', array_map(static fn ($value) => $value === null ? 'NULL' : '\'' . pSQL((string) $value) . '\'', $row)) . ')');
                }
            }
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
                foreach ($this->saved[$key] as $row) {
                    self::db()->insert('configuration', $row, true);
                }
            }
            \Configuration::resetStaticCache();
            \Configuration::loadConfiguration();
        }
        parent::tearDown();
    }

    public function testSettingsSavesNoAccountThatSmailyRefuses(): void
    {
        $this->accounts->save(new Account(0, '', 'demo', self::USERNAME, self::PASSWORD));

        $refused = $this->saver()->saveConnection(
            SettingsScope::current(new StoreLayout(), 0),
            ['subdomain' => 'other', 'username' => self::USERNAME, 'password' => 'a-wrong-password', 'language_mode' => 'single'],
            false
        );

        self::assertSame([['field' => '', 'message' => 'Test your Smaily connection to continue.']], $refused['errors']);
        self::assertSame('Smaily rejected these credentials. Check the subdomain, username and password.', $refused['error']);
        self::assertSame('demo', $this->accounts->find(0, '')->subdomain(), 'the stored account stays');
    }

    public function testAnotherAccountRemovesTheWorkflowsItDoesNotListUntilOneIsPickedAgain(): void
    {
        $this->accounts->save(new Account(0, '', 'demo', self::USERNAME, self::PASSWORD));
        $this->mappings->upsert(0, 'welcome', '', 'default', 42);
        $this->mappings->upsert(0, 'first_order', '', 'default', 99);
        $settings = new AutomationSettings();
        $settings->setEnabled('first_order', true, 0);
        $panels = $this->panels();
        $scope = $this->scope(['en' => 'English']);

        // The same subdomain, typed in another case, is the same account: nothing goes.
        $sameAccount = $panels->connection()->save($scope, ['subdomain' => 'DEMO', 'username' => self::USERNAME, 'password' => '']);
        self::assertSame([[], []], [$sameAccount['errors'], $sameAccount['workflows_removed']]);
        self::assertSame(['welcome' => 42, 'first_order' => 99], $this->workflows(0));

        $other = $panels->connection()->save($scope, ['subdomain' => 'other', 'username' => self::USERNAME, 'password' => self::PASSWORD]);
        self::assertSame([], $other['errors']);
        self::assertSame([['trigger' => 'first_order', 'language' => '']], $other['workflows_removed']);
        self::assertSame(['welcome' => 42], $this->workflows(0), 'the workflow the account lists stays');
        self::assertSame([['trigger' => 'first_order', 'language' => '']], $panels->automations()->values($scope)['lost']);
        self::assertSame('no_workflow', $this->services->get(Router::class)->route('first_order', 0, 'en')->reason());

        // A save that picks no workflow keeps the automation listed; one that picks it ends that.
        $data = ['cart_delay' => '30', 'triggers' => ['welcome' => ['enabled' => false, 'workflow' => '42'], 'first_order' => ['enabled' => true, 'workflow' => ''], 'abandoned_cart' => ['enabled' => false, 'workflow' => '']]];
        self::assertSame([], $panels->automations()->save($scope, $data));
        self::assertCount(1, $panels->automations()->values($scope)['lost']);
        $data['triggers']['first_order']['workflow'] = '43';
        self::assertSame([], $panels->automations()->save($scope, $data));
        self::assertSame([], $panels->automations()->values($scope)['lost']);
        self::assertFalse(self::db()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL(AutomationSettings::LOST_WORKFLOWS) . '\'', false), 'the last entry takes the key with it');
    }

    public function testAnotherLanguageModeRemovesTheWorkflowsOfTheNewModeThatTheAccountDoesNotList(): void
    {
        $this->accounts->save(new Account(0, '', 'demo', self::USERNAME, self::PASSWORD));
        $this->mappings->upsert(0, 'welcome', '', 'default', 99);
        $this->mappings->upsert(0, 'welcome', 'en', 'default', 42);
        $this->mappings->upsert(0, 'welcome', 'et', 'default', 99);
        $scope = $this->scope(['en' => 'English', 'et' => 'Eesti']);

        $saved = $this->panels()->connection()->save($scope, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => '', 'language_mode' => 'b']);

        self::assertSame([['trigger' => 'welcome', 'language' => ''], ['trigger' => 'welcome', 'language' => 'et']], $saved['workflows_removed']);
        self::assertSame(['welcome|en' => 42], $this->languageWorkflows(0));
        self::assertSame(
            [['trigger' => 'welcome', 'language' => ''], ['trigger' => 'welcome', 'language' => 'et']],
            $this->panels()->automations()->values($scope)['lost']
        );
    }

    private function panels(): SetupPanels
    {
        return new SetupPanels($this->services, \Context::getContext()->getTranslator());
    }

    private function saver(): TabSaver
    {
        return new TabSaver($this->panels(), new ShopOverrides($this->accounts, $this->mappings));
    }

    /**
     * @param array<string, string> $languages
     */
    private function scope(array $languages): SetupScope
    {
        return new SetupScope(false, false, true, 0, $languages);
    }

    /**
     * The workflow of each trigger's row of no language at a shop scope.
     *
     * @return array<string, int|null>
     */
    private function workflows(int $idShop): array
    {
        $workflows = [];
        foreach ($this->mappings->rows($idShop) as $row) {
            if ($row['language'] === '') {
                $workflows[$row['trigger']] = $row['workflow_id'];
            }
        }

        return $workflows;
    }

    /**
     * @return array<string, int|null>
     */
    private function languageWorkflows(int $idShop): array
    {
        $workflows = [];
        foreach ($this->mappings->rows($idShop) as $row) {
            $workflows[$row['trigger'] . ($row['language'] === '' ? '' : '|' . $row['language'])] = $row['workflow_id'];
        }

        return $workflows;
    }
}
