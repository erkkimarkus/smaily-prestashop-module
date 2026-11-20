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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * Registers the services and hook listeners of the event queue.
 *
 * An area adds the handler of an event type in its own provider, under handlerId():
 * `$services->set(QueueProvider::handlerId('contact.sync'), fn (Services $s) => new ...)`.
 */
final class QueueProvider implements ProviderInterface
{
    public const EVENT_QUEUE = 'queue.event_queue';

    public const FLUSHER = 'queue.flusher';

    public static function handlerId(string $eventType): string
    {
        return 'queue.handler.' . $eventType;
    }

    public function register(Services $services): void
    {
        $services->set(self::EVENT_QUEUE, fn (): EventQueue => new EventQueue());
        $services->set(self::FLUSHER, fn (Services $services): Flusher => new Flusher(
            $services->get(self::EVENT_QUEUE),
            static function (string $eventType) use ($services): ?HandlerInterface {
                try {
                    $handler = $services->get(self::handlerId($eventType));
                } catch (\OutOfBoundsException $missing) {
                    return null;
                }

                return $handler instanceof HandlerInterface ? $handler : null;
            }
        ));
    }
}
