<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;
use Tests\Integration\IntegrationTestCase;

/**
 * The token of each door exists once something reads it, unless an empty one is stored; and the
 * tick state survives the configuration table.
 */
final class TickSettingsTest extends IntegrationTestCase
{
    private const KEYS = [
        TickSettings::TICK_TOKEN,
        TickSettings::CUSTOMER_CRON_TOKEN,
        TickSettings::CART_CRON_TOKEN,
        TickSettings::TICK_STATE,
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->saved[$key] = self::storedValue($key);
            \Configuration::deleteByName($key);
        }
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach ($this->saved as $key => $value) {
                \Configuration::deleteByName($key);
                if ($value !== false) {
                    \Configuration::updateGlobalValue($key, $value);
                }
            }
        }
        parent::tearDown();
    }

    public function testCreatesAndKeepsTheTokenOfADoorThatHasNone(): void
    {
        $settings = new TickSettings();
        $tokens = [];
        foreach ([TickSettings::TICK_TOKEN, TickSettings::CUSTOMER_CRON_TOKEN, TickSettings::CART_CRON_TOKEN] as $key) {
            $token = $settings->token($key, 1);

            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
            self::assertSame($token, $settings->token($key, 1));
            self::assertSame($token, $this->storedGlobal($key));
            $tokens[] = $token;
        }
        self::assertCount(3, array_unique($tokens), 'each door has a token of its own');
    }

    public function testAnEmptyStoredTokenKeepsTheDoorClosed(): void
    {
        \Configuration::updateGlobalValue(TickSettings::CART_CRON_TOKEN, '');

        self::assertSame('', (new TickSettings())->token(TickSettings::CART_CRON_TOKEN, 1));
        self::assertNull($this->storedGlobal(TickSettings::CART_CRON_TOKEN), 'no token is created');
    }

    public function testReadsALegacyTokenAsStored(): void
    {
        \Configuration::updateGlobalValue(TickSettings::CUSTOMER_CRON_TOKEN, 'legacytoken123');

        self::assertSame('legacytoken123', (new TickSettings())->token(TickSettings::CUSTOMER_CRON_TOKEN, 1));
    }

    public function testKeepsATokenThatAnotherRequestStoredAfterThisOneStarted(): void
    {
        self::db()->insert('configuration', [
            'name' => TickSettings::TICK_TOKEN,
            'value' => 'storedelsewhere',
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        self::assertSame('storedelsewhere', (new TickSettings())->token(TickSettings::TICK_TOKEN, 1));
        self::assertSame('storedelsewhere', $this->storedGlobal(TickSettings::TICK_TOKEN));
    }

    public function testStoresTheTickState(): void
    {
        $settings = new TickSettings();
        self::assertNull($settings->state()->lastTickAt());
        self::assertNull($settings->storedState()->lastTickAt());

        $settings->saveState(TickState::fromJson(null)->withTick('cart_cron', 1234)->withJobRun('queue_flush', 1234));

        foreach ([$settings->state(), $settings->storedState()] as $state) {
            self::assertSame(1234, $state->lastTickAt());
            self::assertSame('cart_cron', $state->lastSource());
            self::assertSame(1234, $state->jobRanAt('queue_flush'));
        }
    }

    public function testReadsTheStateAfterTheRequestDroppedItsLoadedConfiguration(): void
    {
        $settings = new TickSettings();
        $settings->saveState(TickState::fromJson(null)->withTick('tick', 1234));
        \Configuration::deleteByName('SMAILY_CONNECT_TEST_UNRELATED');

        self::assertSame(1234, $settings->state()->lastTickAt());
    }

    public function testReadsTheStoredStateThatAnotherRequestWrote(): void
    {
        $settings = new TickSettings();
        $settings->saveState(TickState::fromJson(null)->withTick('tick', 1000));
        self::db()->update(
            'configuration',
            ['value' => pSQL(TickState::fromJson(null)->withTick('traffic', 2000)->toJson(), true)],
            'name = \'' . TickSettings::TICK_STATE . '\''
        );

        self::assertSame(1000, $settings->state()->lastTickAt(), 'the request keeps what it loaded');
        self::assertSame(2000, $settings->storedState()->lastTickAt());
    }

    private function storedGlobal(string $key): ?string
    {
        $value = self::db()->getValue(sprintf(
            'SELECT `value` FROM `%sconfiguration` WHERE `name` = \'%s\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL',
            _DB_PREFIX_,
            pSQL($key)
        ), false);

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * The stored global value of $key, or false when none is stored. Configuration::get() loads the
     * configuration again after a deleteByName(); hasKey() does not.
     *
     * @return string|false
     */
    private static function storedValue(string $key)
    {
        $value = \Configuration::getGlobalValue($key);

        return $value === false ? false : (string) $value;
    }
}
