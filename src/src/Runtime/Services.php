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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;

/**
 * The composition root of the runtime: queues, clients, routers, builders and the tick.
 *
 * Hooks, front controllers, the upgrade script and the admin controllers reach the runtime through
 * Services::instance(). Each area registers its services and its hook listeners in its own provider;
 * a service is built on first use and then shared.
 */
final class Services
{
    /**
     * The provider of each area, in the order they register.
     *
     * @var list<class-string<ProviderInterface>>
     */
    public const PROVIDERS = [
        Provider\SmailyProvider::class,
        Provider\QueueProvider::class,
        Provider\SchedulerProvider::class,
        Provider\ScopeProvider::class,
        Provider\ContactSyncProvider::class,
        Provider\ContactsProvider::class,
        Provider\AutomationProvider::class,
        Provider\EngineProvider::class,
        Provider\StorefrontProvider::class,
        Provider\PrivacyProvider::class,
        Provider\ReportProvider::class,
        Provider\MigrationProvider::class,
    ];

    private static ?Services $instance = null;

    /** @var array<string, callable(Services): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, list<callable(array<string, mixed>): mixed>> */
    private array $listeners = [];

    /** @var array<string, list<callable(array<string, mixed>): mixed>> */
    private array $beforeUpgrade = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = self::fromProviders(self::PROVIDERS);
        }

        return self::$instance;
    }

    /**
     * @param list<class-string<ProviderInterface>> $providers
     */
    public static function fromProviders(array $providers): self
    {
        $services = new self();
        foreach ($providers as $provider) {
            (new $provider())->register($services);
        }

        return $services;
    }

    /**
     * @param callable(Services): object $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \OutOfBoundsException(sprintf('No service "%s" is registered.', $id));
            }
            $this->instances[$id] = ($this->factories[$id])($this);
        }

        return $this->instances[$id];
    }

    /**
     * @param callable(array<string, mixed>): mixed $listener
     * @param bool $beforeUpgrade whether the listener also runs while the stored schema version
     *                            differs from the code's (Hook\Dispatcher); no other listener does then
     */
    public function listen(string $hook, callable $listener, bool $beforeUpgrade = false): void
    {
        $this->listeners[$hook][] = $listener;
        if ($beforeUpgrade) {
            $this->beforeUpgrade[$hook][] = $listener;
        }
    }

    /**
     * @return list<callable(array<string, mixed>): mixed>
     */
    public function listeners(string $hook): array
    {
        return $this->listeners[$hook] ?? [];
    }

    /**
     * The listeners of $hook that were registered to run also before the upgrade has finished.
     *
     * @return list<callable(array<string, mixed>): mixed>
     */
    public function listenersBeforeUpgrade(string $hook): array
    {
        return $this->beforeUpgrade[$hook] ?? [];
    }
}
