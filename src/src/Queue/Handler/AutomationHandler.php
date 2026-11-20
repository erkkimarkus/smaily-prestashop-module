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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue\Handler;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;

/**
 * Sends the rows of one store automation (`automation.welcome`, `automation.first_order`) to Smaily,
 * one row per request, as the Magento connector does: a failure of one contact then never repeats
 * the automation for another. Each row goes through TriggerSender in the row's shop and language
 * (the contact's, stored when the event was queued, never the request's that sends it), and the
 * sender's outcome is the row's: sent, a Skipped with the router's reason (`automation_off`,
 * `no_workflow`, `no_account`, `no_credentials`), or a Failure.
 *
 * The payload is {"email": <address>, "fields": {<name>: <value>, …}}: the fields go into the
 * address as they are, the automation's `*_automation_at` marker among them (stamped when the event
 * was queued). A payload of another shape fails for good with `malformed_payload`.
 */
final class AutomationHandler implements HandlerInterface
{
    public const FAILED_MALFORMED_PAYLOAD = 'malformed_payload';

    private string $trigger;

    private TriggerSender $sender;

    /**
     * @param string $trigger the automation of the rows (Automation\Trigger)
     */
    public function __construct(string $trigger, TriggerSender $sender)
    {
        $this->trigger = $trigger;
        $this->sender = $sender;
    }

    public function handle(array $events): array
    {
        $outcomes = [];
        foreach ($events as $event) {
            $id = (int) $event['id'];
            $payload = self::decode((string) $event['payload']);
            $outcomes[$id] = $payload === null
                ? Failure::permanent(self::FAILED_MALFORMED_PAYLOAD)
                : $this->sender->send($this->trigger, (int) $event['id_shop'], (string) ($event['language'] ?? ''), $payload['email'], $payload['fields']);
        }

        return $outcomes;
    }

    /**
     * The payload of a row, or null when it is not one.
     *
     * @return array{email: string, fields: array<string, string|int|float|bool>}|null
     */
    private static function decode(string $json): ?array
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !is_string($payload['email'] ?? null) || trim($payload['email']) === '' || !is_array($payload['fields'] ?? null)) {
            return null;
        }
        foreach ($payload['fields'] as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                return null;
            }
        }

        return ['email' => $payload['email'], 'fields' => $payload['fields']];
    }
}
