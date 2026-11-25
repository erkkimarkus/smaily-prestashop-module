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

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\LayoutConnections;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\Fixtures\EngineMockServer;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/EngineTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * A change of the shop layout carries one Campaign Intelligence connection over and ends the
 * others (owner, 2026-11-23; ARCHITECTURE 8.9), against the engine mock, in a store with two
 * shops.
 */
final class LayoutConnectionsTest extends EngineTestCase
{
    use MultistoreShop;

    private const UNINSTALL = '/api/v1/store/uninstall';

    private int $shopA;

    private int $shopB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forget();
        $this->openMultistore();
        $this->shopA = $this->defaultShopId();
        $this->shopB = $this->addShop($this->addShopGroup(false));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->forget();
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testToSeparateStoresTheConnectionOfAllShopsBecomesOneShopsOwn(): void
    {
        $shared = $this->connect();
        $this->jobs()->startAll($shared);

        self::assertSame([$shared->id(), []], [$this->change()->plan(0)['carried']->id(), $this->change()->plan(0)['ended']]);
        $this->separate(true);
        $this->change()->change(0, $this->shopA);

        $carried = $this->connections()->find($this->shopA);
        self::assertNull($this->connections()->find(0), 'no row of the other layout stays');
        self::assertSame([$shared->id(), $shared->apiKey(), $shared->tenantId()], [$carried->id(), $carried->apiKey(), $carried->tenantId()], 'the same connection: its row, its key, its engine store');
        self::assertSame([], $this->engine->requestsTo(self::UNINSTALL), 'the engine is told of no end');
        // The driver answers numbers as text on PHP 8.0 and as integers on PHP 8.1 and later.
        self::assertSame(
            [[$this->shopA, 3]],
            array_map(
                static fn (array $row): array => array_map('intval', array_values($row)),
                self::db()->executeS('SELECT `id_shop`, COUNT(*) AS `jobs` FROM `' . _DB_PREFIX_ . JobStore::TABLE . '` WHERE `id_connection` = ' . $shared->id() . ' GROUP BY `id_shop`', true, false)
            ),
            'its imports are listed under the shop'
        );
        self::assertSame(['queued', 'queued', 'queued'], array_column($this->jobs()->progress($carried), 'status'), 'and still wait to run');
        self::assertSame(HealthCheck::CONNECTED, $this->services->get(HealthCheck::class)->check($carried), 'the engine still takes its key');
    }

    public function testToOneStoreTheShopsConnectionServesAllShopsAndTheOthersEnd(): void
    {
        $this->separate(true);
        $first = $this->connect($this->shopA);
        $second = $this->connect($this->shopB);
        $this->jobs()->start($first, HistoryJobs::CATALOG);

        $plan = $this->change()->plan($this->shopB);
        self::assertSame([$second->id(), [$first->id()]], [$plan['carried']->id(), array_map(static fn ($connection): int => $connection->id(), $plan['ended'])]);
        $this->separate(false);
        $this->change()->change($this->shopB, 0);

        self::assertSame(1, self::connectionRows());
        $carried = $this->connections()->find(0);
        self::assertSame([$second->id(), $second->apiKey()], [$carried->id(), $carried->apiKey()]);
        $requests = $this->engine->requestsTo(self::UNINSTALL);
        self::assertCount(1, $requests, 'the engine is told once, of the connection that ended');
        self::assertSame(EngineMockServer::keyId($first->apiKey()), $requests[0]['key']);
        self::assertSame('uninstall', $this->engine->connections()[EngineMockServer::keyId($first->apiKey())]['revoked']);
        self::assertFalse($this->engine->connections()[EngineMockServer::keyId($second->apiKey())]['revoked'], 'the carried connection keeps its key');
        self::assertSame($first->prefix(), $this->services->get(EngineSettings::class)->previousPrefix($this->shopA), 'as a Disconnect remembers it');
        self::assertSame(HealthCheck::CONNECTED, $this->services->get(HealthCheck::class)->check($carried));
        self::assertSame('queued', $this->jobs()->latest($first->id(), HistoryJobs::CATALOG)['status'], 'the ended connection\'s import waits for the tick to cancel it');
    }

    public function testARowOfTheOtherLayoutThatHoldsTheCarriedConnectionsPlaceEndsFirst(): void
    {
        $shared = $this->connect();
        $this->separate(true);
        $stale = $this->connect($this->shopA);
        $other = $this->connect($this->shopB);

        $this->change()->change(0, $this->shopA);

        self::assertSame(1, self::connectionRows());
        self::assertSame($shared->apiKey(), $this->connections()->find($this->shopA)->apiKey());
        self::assertSame(
            [EngineMockServer::keyId($stale->apiKey()), EngineMockServer::keyId($other->apiKey())],
            array_column($this->engine->requestsTo(self::UNINSTALL), 'key'),
            'the row that held the place first, then every other one'
        );
    }

    public function testWithoutAConnectionToCarryEveryOtherConnectionEnds(): void
    {
        $this->separate(true);
        $first = $this->connect($this->shopA);
        self::assertNull($this->change()->plan($this->shopB)['carried']);

        $this->separate(false);
        $this->change()->change($this->shopB, 0);

        self::assertSame(0, self::connectionRows());
        self::assertSame([EngineMockServer::keyId($first->apiKey())], array_column($this->engine->requestsTo(self::UNINSTALL), 'key'));
    }

    public function testAnEngineThatDoesNotAnswerDoesNotStopTheChange(): void
    {
        $this->separate(true);
        $this->connect($this->shopA);
        $second = $this->connect($this->shopB);
        $this->engine->fail('uninstall', 503);

        $this->separate(false);
        $this->change()->change($this->shopB, 0);

        self::assertSame(1, self::connectionRows());
        self::assertSame($second->apiKey(), $this->connections()->find(0)->apiKey());
    }

    public function testTheCookieBannerConfirmationNeverComesToCoverOtherVisitors(): void
    {
        $settings = $this->services->get(EngineSettings::class);

        // To separate stores: the confirmation given for all shops stays with the one shop.
        $settings->setConsentConfirmed(true, 0);
        $this->separate(true);
        $this->change()->change(0, $this->shopA);
        self::assertTrue($settings->consentConfirmed($this->shopA));
        self::assertFalse($settings->consentConfirmed($this->shopB), 'no other shop holds one');
        self::assertFalse($settings->consentConfirmed(0));

        // To one store: one shop's confirmation is not given for the visitors of every shop.
        $this->separate(false);
        $this->change()->change($this->shopA, 0);
        self::assertFalse($settings->consentConfirmed(0));
        self::assertFalse($settings->consentConfirmed($this->shopA));
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . EngineSettings::CONSENT_CONFIRMED . '\'', false));

        // A confirmation that was never given is not made.
        $this->separate(true);
        $this->change()->change(0, $this->shopB);
        self::assertFalse($settings->consentConfirmed($this->shopB));
    }

    private function change(): LayoutConnections
    {
        return new LayoutConnections(
            $this->connections(),
            $this->services->get(Disconnector::class),
            $this->services->get(EngineSettings::class),
            $this->jobs()
        );
    }

    private function jobs(): HistoryJobs
    {
        return $this->services->get(HistoryJobs::class);
    }

    /**
     * The layout that is in force, as Connections reads it.
     */
    private function separate(bool $separate): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, $separate ? '1' : '0');
        $this->services = $this->runtime();
    }

    private function forget(): void
    {
        self::db()->delete(JobStore::TABLE, '`type` LIKE \'engine\\_%\'');
        \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
    }
}
