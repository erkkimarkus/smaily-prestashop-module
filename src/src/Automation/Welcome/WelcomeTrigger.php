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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * Queues the welcome automation for a shopper's own newsletter consent in the storefront, the rule
 * of the WooCommerce connector (PRO-4093) and the Magento connector (PRO-3580): a registration with
 * the box ticked, the box at the order step, the account page, and the newsletter block, its
 * verification link included. It reads the contact events that the capture hands over at the end of
 * the request (Contacts\Capture\ContactEvents), one per shop and address, and queues one
 * `automation.welcome` row for an event that gained the consent, when:
 *  - the request is a storefront page (a front controller): a consent that the back office, the
 *    reconcile from Smaily, an import or the upgrade writes starts no welcome;
 *  - the welcome is on in the contact's shop;
 *  - the shop's contact sync is active (on and confirmed), and its consent mode sends the contact
 *    (ContactAudience): the welcome follows the contact sync, as in the sibling connectors (owner,
 *    2026-11-26; Linear CON-452).
 * Each storefront subscribe starts it, a re-subscribe too; there is no once-per-contact record.
 *
 * The row carries the address and the fields that the contact sync sends (`store` and the selected
 * fields), with the `welcome_automation_at` marker stamped now, in the contact's shop and language.
 */
final class WelcomeTrigger
{
    public const EVENT_TYPE = 'automation.welcome';

    private EventQueue $queue;

    private AutomationSettings $automations;

    private ContactsSettings $contacts;

    private SubscriberPayloadBuilder $builder;

    /** @var callable(): bool */
    private $storefront;

    /**
     * @param callable(): bool $storefront whether the request is a storefront page
     */
    public function __construct(EventQueue $queue, AutomationSettings $automations, ContactsSettings $contacts, SubscriberPayloadBuilder $builder, callable $storefront)
    {
        $this->queue = $queue;
        $this->automations = $automations;
        $this->contacts = $contacts;
        $this->builder = $builder;
        $this->storefront = $storefront;
    }

    /**
     * A contact event of the request (ContactEvents::listen()).
     *
     * @param string|null $language the contact's ISO code
     * @param array<string, mixed> $payload the event's `contact.sync` payload
     *
     * @return int the number of rows queued
     */
    public function onContactEvent(int $idShop, ?string $language, array $payload): int
    {
        if (!($payload['subscribed'] ?? false) || !($payload['consent_changed'] ?? false) || !($this->storefront)()) {
            return 0;
        }
        if (!$this->automations->enabled(Trigger::WELCOME, $idShop) || !$this->contacts->syncActive($idShop)) {
            return 0;
        }
        $audience = new ContactAudience($this->contacts->mode($idShop));
        if (!$audience->syncs((string) $payload['source'], true, true, (bool) ($payload['checkout'] ?? false), (bool) ($payload['guest'] ?? false))) {
            return 0;
        }

        $fields = $this->builder->build((string) $payload['email'], $idShop, '', $this->contacts->fields($idShop), (array) ($payload['customer'] ?? []), null);
        $email = (string) $fields['email'];
        unset($fields['email']);
        $this->queue->enqueue(
            self::EVENT_TYPE,
            $idShop,
            ['email' => $email, 'fields' => $fields + Trigger::marker(Trigger::WELCOME)],
            ContactEntity::of($email),
            $language
        );

        return 1;
    }
}
