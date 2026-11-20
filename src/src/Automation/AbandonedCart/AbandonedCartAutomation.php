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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;

/**
 * Registers the services, the queue handler, the jobs and the hook listeners of the abandoned-cart
 * automation. Runtime\Provider\AutomationProvider calls it.
 *
 * - The job `abandoned_cart` runs the sweep every 5 minutes for up to 10 s (Magento's
 *   Cron\AbandonedCart): it queues the due reminders, which the next queue flush sends.
 * - The handler of `automation.abandoned_cart` sends them (AbandonedCartHandler).
 * - An order (`actionValidateOrder`) withdraws every reminder of its address that is not sent yet,
 *   also one already queued: the handler then closes that row as `ordered`.
 */
final class AbandonedCartAutomation implements ProviderInterface
{
    public const JOB = 'abandoned_cart';

    public const JOB_INTERVAL_SECONDS = 300;

    public const JOB_BUDGET_SECONDS = 10;

    public function register(Services $services): void
    {
        $services->set(CartTracker::class, fn (): CartTracker => new CartTracker());
        $services->set(CartAddress::class, fn (): CartAddress => new CartAddress());
        $services->set(AbandonedCartSweeper::class, fn (Services $services): AbandonedCartSweeper => new AbandonedCartSweeper(
            $services->get(AutomationSettings::class),
            $services->get(CartTracker::class),
            new CartPayloadBuilder(),
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(ScopeResolver::class),
            static fn (): array => array_map('intval', \Shop::getShops(true, null, true))
        ));
        $services->set(QueueProvider::handlerId(AbandonedCartHandler::EVENT_TYPE), fn (Services $services): AbandonedCartHandler => new AbandonedCartHandler(
            $services->get(CartTracker::class),
            $services->get(CartAddress::class),
            $services->get(Router::class),
            $services->get(TriggerSender::class),
            $services->get(ContactsSettings::class),
            $services->get(Transport::class)
        ));

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::JOB, self::JOB_INTERVAL_SECONDS, self::JOB_BUDGET_SECONDS, static function (Deadline $deadline) use ($services): void {
            $services->get(AbandonedCartSweeper::class)->sweep($deadline);
        }));
        $services->listen('actionValidateOrder', static function (array $params) use ($services): void {
            $customer = $params['customer'] ?? null;
            $order = $params['order'] ?? null;
            if ($customer instanceof \Customer && $order instanceof \Order && (string) $customer->email !== '') {
                $services->get(CartTracker::class)->withdraw((string) $customer->email, (int) $order->id_cart, AbandonedCartHandler::SKIPPED_ORDERED);
            }
        });
        (new Capture\CheckoutCapture())->register($services);
    }
}
