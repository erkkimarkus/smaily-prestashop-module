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

namespace PrestaShop\Module\SmailyForPrestaShop\Hook;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * Hands each hook call of the main class to the listeners that the providers registered for it.
 *
 * While the stored schema version differs from the one the code needs (files copied before
 * PrestaShop ran the upgrade), no listener runs and the hook returns null. A listener that throws
 * fails alone: the error is logged, the other listeners run, and the shop's request goes on.
 *
 * The listeners' results combine: strings join (display hooks), arrays merge (moduleRoutes,
 * additionalCustomerFormFields), any other value replaces the previous one.
 */
final class Dispatcher
{
    private static ?Dispatcher $instance = null;

    /** @var callable(): bool */
    private $schemaIsCurrent;

    /** @var callable(): Services */
    private $services;

    /** @var callable(string, \Throwable): void */
    private $reportFailure;

    /**
     * @param callable(): bool $schemaIsCurrent
     * @param callable(): Services $services
     * @param callable(string, \Throwable): void $reportFailure
     */
    public function __construct(callable $schemaIsCurrent, callable $services, callable $reportFailure)
    {
        $this->schemaIsCurrent = $schemaIsCurrent;
        $this->services = $services;
        $this->reportFailure = $reportFailure;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return mixed
     */
    public static function dispatch(string $hook, array $params = [])
    {
        if (self::$instance === null) {
            self::$instance = new self(
                [Schema::class, 'isCurrent'],
                [Services::class, 'instance'],
                [self::class, 'logFailure']
            );
        }

        return self::$instance->run($hook, $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return mixed
     */
    public function run(string $hook, array $params)
    {
        if (!($this->schemaIsCurrent)()) {
            return null;
        }

        $result = null;
        foreach (($this->services)()->listeners($hook) as $listener) {
            try {
                $result = self::combine($result, $listener($params));
            } catch (\Throwable $error) {
                ($this->reportFailure)($hook, $error);
            }
        }

        return $result;
    }

    /**
     * The message names the error's class and place only: an exception message can carry a
     * secret or an address.
     */
    public static function logFailure(string $hook, \Throwable $error): void
    {
        \PrestaShopLogger::addLog(
            sprintf(
                'Smaily Connect: a listener of %s failed with %s at %s:%d',
                $hook,
                get_class($error),
                basename($error->getFile()),
                $error->getLine()
            ),
            3
        );
    }

    /**
     * @param mixed $carry
     * @param mixed $value
     *
     * @return mixed
     */
    private static function combine($carry, $value)
    {
        if ($value === null) {
            return $carry;
        }
        if (is_string($carry) && is_string($value)) {
            return $carry . $value;
        }
        if (is_array($carry) && is_array($value)) {
            return array_merge($carry, $value);
        }

        return $value;
    }
}
