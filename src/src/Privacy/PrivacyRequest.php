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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

/**
 * A privacy request for one shopper: the export and the erasure, over the store's own tables
 * (LocalData) and Campaign Intelligence. PrestaShop's GDPR module calls both through its hooks
 * (GdprHooks); the Log's "Privacy request" form calls them in every store.
 *
 * - **Export.** The module's own rows, and what each connection's engine store holds (contract
 *   §8): one attempt per connection, no retry. An engine that does not answer leaves
 *   NOT_AVAILABLE for that connection; nothing of its answer is kept or shown. An engine that
 *   does not know the shopper leaves NO_DATA.
 * - **Erasure.** The store's rows first, then one `engine.customer_delete` row per connection
 *   (contract §9, CustomerDeleteHandler): the engine's deletion is queued, never called in the
 *   request, so it survives an engine that is down. Smaily contacts are not deleted: Smaily owns
 *   them.
 *
 * Both cover every shop and every connection of the installation: a shopper is an address.
 */
class PrivacyRequest
{
    public const OK = 'ok';

    public const NO_DATA = 'no_data';

    public const NOT_AVAILABLE = 'not_available';

    private LocalData $local;

    private Connections $connections;

    private Client $client;

    private EventQueue $queue;

    private Crypto $crypto;

    public function __construct(LocalData $local, Connections $connections, Client $client, EventQueue $queue, Crypto $crypto)
    {
        $this->local = $local;
        $this->connections = $connections;
        $this->client = $client;
        $this->queue = $queue;
        $this->crypto = $crypto;
    }

    /**
     * @param int $idCustomer the customer account of the request, 0 when it names an address only
     *
     * @return array{
     *     store: array<string, mixed>,
     *     campaign_intelligence: list<array{connection: int, status: string, data: array<string, mixed>|null}>
     * } `store` as LocalData::export(); `status` is OK, NO_DATA or NOT_AVAILABLE
     */
    public function export(string $email, int $idCustomer = 0): array
    {
        $engine = [];
        foreach (trim($email) === '' ? [] : $this->connections->all() as $connection) {
            $answer = ['connection' => $connection->id(), 'status' => self::OK, 'data' => null];
            try {
                $answer['data'] = $this->client->customerExport($connection, $email);
            } catch (EngineException $error) {
                $answer['status'] = $error instanceof EngineRequestException && $error->httpStatus() === 404 ? self::NO_DATA : self::NOT_AVAILABLE;
                if ($answer['status'] === self::NOT_AVAILABLE) {
                    Log::failure('the Campaign Intelligence export of a privacy request', $error);
                }
            }
            $engine[] = $answer;
        }

        return ['store' => $this->local->export($email, $idCustomer), 'campaign_intelligence' => $engine];
    }

    /**
     * @param int $idCustomer the customer account of the request, 0 when it names an address only
     * @param int $idShop the shop the request was made in: the Log shows the row of the
     *                    installation-wide connection there
     *
     * @return array{queue_removed: int, queue_cleared: int, carts: int, attribution: int, codes_removed: int, codes_kept: int, engine_queued: int}
     */
    public function erase(string $email, int $idCustomer, int $idShop): array
    {
        $counts = $this->local->erase($email, $idCustomer);
        $queued = 0;
        foreach (trim($email) === '' ? [] : $this->connections->all() as $connection) {
            $this->queue->enqueue(
                CustomerDeleteHandler::EVENT_TYPE,
                $connection->idShop() > 0 ? $connection->idShop() : $idShop,
                CustomerDeleteHandler::payload($this->crypto, $connection, $email),
                ResendGuard::ERASED_ENTITY
            );
            ++$queued;
        }

        return $counts + ['engine_queued' => $queued];
    }
}
