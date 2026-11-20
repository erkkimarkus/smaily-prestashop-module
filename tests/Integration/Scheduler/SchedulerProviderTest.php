<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickReport;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;
use Tests\Integration\IntegrationTestCase;

/**
 * The runtime's tick runs the event queue flush and the queue janitor, then the jobs that later
 * areas register; and the page hooks reach the fallback tick.
 */
final class SchedulerProviderTest extends IntegrationTestCase
{
    /** @var string|false */
    private $savedState = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::clearQueue();
        $this->savedState = self::storedValue(TickSettings::TICK_STATE);
        \Configuration::deleteByName(TickSettings::TICK_STATE);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::clearQueue();
            \Configuration::deleteByName(TickSettings::TICK_STATE);
            if ($this->savedState !== false) {
                \Configuration::updateGlobalValue(TickSettings::TICK_STATE, $this->savedState);
            }
        }
        parent::tearDown();
    }

    public function testTheTickFlushesTheQueueUntilNothingIsDueThenRunsTheJanitorAndLaterJobs(): void
    {
        $services = Services::fromProviders(Services::PROVIDERS);
        $sent = [];
        $record = function (array $events) use (&$sent): array {
            $outcomes = [];
            foreach ($events as $row) {
                $sent[] = (int) $row['id'];
                $outcomes[(int) $row['id']] = true;
            }

            return $outcomes;
        };
        $services->set(QueueProvider::handlerId('test.tick'), fn (): HandlerInterface => new class($record) implements HandlerInterface {
            /** @var callable */
            private $record;

            public function __construct(callable $record)
            {
                $this->record = $record;
            }

            public function handle(array $events): array
            {
                return ($this->record)($events);
            }
        });
        $services->listen(SchedulerProvider::JOBS, fn (): Job => new Job('later_area', 60, 5, fn () => null));
        // The update check asks no real GitHub: its request finds no release.
        $services->set(LatestRelease::class, fn (): LatestRelease => new LatestRelease(new class() implements Transport {
            public function request(string $method, string $url, array $options = []): Response
            {
                return new Response(404, '');
            }
        }, '3.0.0'));
        $queue = new EventQueue();
        $ids = [];
        for ($i = 0; $i < 250; ++$i) {
            $ids[] = $queue->enqueue('test.tick', 1, ['n' => $i]);
        }

        $report = $services->get(Tick::class)->run('tick', 50);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame(['queue_flush', 'queue_janitor', 'contacts_reconcile', 'contacts_refresh', 'contacts_import', 'migration', 'update_check', 'later_area'], $report->jobs());
        self::assertSame($ids, $sent, 'two batches of the flush sent every row');
    }

    public function testThePageHooksReachTheFallbackTick(): void
    {
        $services = Services::fromProviders(Services::PROVIDERS);

        foreach (SchedulerProvider::TRAFFIC_HOOKS as $hook) {
            self::assertCount(1, $services->listeners($hook), $hook);
        }
    }

    private static function clearQueue(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
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
