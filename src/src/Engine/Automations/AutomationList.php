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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Automations;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The engine's automated emails as the Automations tab lists them: the catalogue that the engine
 * offers the store now (contract §11) with the store's configuration rows (§12), as the Magento
 * connector's AutomationsForm merges them. The catalogue decides which automations exist: nothing
 * is listed that it does not name, and none is known here by its key.
 *
 * An automation without a configuration row is off and in test mode, with the defaults of the
 * sibling connectors. The name and the description are the engine's own text, in the employee's
 * language where the engine gives it, else in English.
 *
 * An email's state comes from its §12 `setup[]` item where the engine gives one (setup()): test
 * mode is on in every state but `live`, as in the sibling connectors (CON-455, CON-458).
 */
final class AutomationList
{
    /** A catalogue key that a page can carry as a field name. */
    public const KEY_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/D';

    /** The days between two emails to one customer that a row without a stored value shows. */
    public const DEFAULT_COOLDOWN_DAYS = 7;

    /** §12 `notice`: the email starts by itself once its Smaily workflow is active. */
    public const NOTICE_SENDER = 'starts_when_sender_verified';

    /** §12 blocker after an automatic or an operator's stop, until Smaily reviews it. */
    public const STOPPED_NEEDS_REVIEW = 'stopped_needs_review';

    /**
     * @param array<string, mixed> $catalog the answer of §11
     * @param array<string, mixed> $config the answer of §12
     * @param string $language the employee's language, an ISO 639-1 code
     *
     * @return list<array{key: string, name: string, description: string, enabled: bool, mode: string, setup: array{test_mode: bool, daily_limit: int|null, stopped: bool, notice: string}|null, workflow_id: string, language_mode: string, original_map: array<string, string>, cooldown_days: int, daily_cap: int|null, test_emails: string}>
     */
    public static function rows(array $catalog, array $config, string $language): array
    {
        $configured = [];
        foreach (is_array($config['configs'] ?? null) ? $config['configs'] : [] as $row) {
            if (is_array($row) && is_string($row['trigger_key'] ?? null)) {
                $configured[$row['trigger_key']] = $row;
            }
        }
        $states = self::states($config);

        $rows = [];
        foreach (is_array($catalog['triggers'] ?? null) ? $catalog['triggers'] : [] as $trigger) {
            $key = is_array($trigger) ? ($trigger['key'] ?? null) : null;
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                continue;
            }
            $stored = $configured[$key] ?? [];
            $map = self::map($stored['automation_map'] ?? null);
            $emails = is_array($stored['test_emails'] ?? null) ? array_filter($stored['test_emails'], 'is_string') : [];
            $enabled = ($stored['enabled'] ?? false) === true;
            $setup = $states[$key] ?? null;
            $rows[] = [
                'key' => $key,
                'name' => self::localized($trigger, 'name', $language, $key),
                'description' => self::localized($trigger, 'description', $language, ''),
                'enabled' => $enabled,
                // The pill: Off, Test mode, Stopped or Active. Without a setup item, fail closed: only a
                // stored `false` shows an automation out of test mode.
                'mode' => $setup !== null ? $setup['mode'] : (!$enabled ? 'off' : (($stored['test_mode'] ?? true) !== false ? 'test' : 'active')),
                'setup' => $setup === null ? null : array_diff_key($setup, ['mode' => true]),
                // A per-language map of another connector holds its workflow of no language under
                // `fallback`.
                'workflow_id' => $map['id'] ?? $map['fallback'] ?? '',
                'language_mode' => ($stored['language_mode'] ?? null) === 'per_language' ? 'per_language' : 'single',
                'original_map' => $map,
                'cooldown_days' => is_int($stored['cooldown_days'] ?? null) ? $stored['cooldown_days'] : self::DEFAULT_COOLDOWN_DAYS,
                'daily_cap' => is_int($stored['daily_cap'] ?? null) ? $stored['daily_cap'] : null,
                'test_emails' => implode(', ', $emails),
            ];
        }

        return $rows;
    }

    /**
     * Each catalogue email's state from a §12 answer, by key (an engine before contract v1.15.0
     * gives none): the pill's `mode` (`off`, `test`, `stopped` while a stop waits for Smaily's
     * review, as WooCommerce, or `active`), whether test mode is on (every
     * state but `live`), today's `daily_limit` of a live email, whether a stop waits for Smaily's
     * review, and the `notice` where it is one the contract names ('' otherwise).
     *
     * @param array<string, mixed> $config the answer of §12
     *
     * @return array<string, array{mode: string, test_mode: bool, daily_limit: int|null, stopped: bool, notice: string}>
     */
    public static function states(array $config): array
    {
        $states = [];
        foreach (is_array($config['setup'] ?? null) ? $config['setup'] : [] as $item) {
            if (!is_array($item) || !is_string($item['trigger_key'] ?? null)) {
                continue;
            }
            $state = $item['setup_state'] ?? null;
            $live = $state === 'live';
            $stopped = $state === 'stopped'
                || (is_array($item['go_live_blockers'] ?? null) && in_array(self::STOPPED_NEEDS_REVIEW, $item['go_live_blockers'], true));
            $states[$item['trigger_key']] = [
                'mode' => $live ? 'active' : ($stopped ? 'stopped' : ($state === 'test' ? 'test' : 'off')),
                'test_mode' => !$live,
                'daily_limit' => $live && is_int($item['daily_limit'] ?? null) ? $item['daily_limit'] : null,
                'stopped' => $stopped,
                'notice' => ($item['notice'] ?? null) === self::NOTICE_SENDER ? self::NOTICE_SENDER : '',
            ];
        }

        return $states;
    }

    /**
     * A workflow map as the engine stores it: its keys with the workflow ids as strings.
     *
     * @param mixed $map
     *
     * @return array<string, string>
     */
    public static function map($map): array
    {
        $ids = [];
        foreach (is_array($map) ? $map : [] as $key => $id) {
            if (is_string($id) || is_int($id)) {
                $ids[(string) $key] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * A catalogue text in the employee's language, else in English, else $default.
     *
     * @param array<string, mixed> $trigger
     */
    private static function localized(array $trigger, string $field, string $language, string $default): string
    {
        foreach ([$language, 'en'] as $code) {
            $text = $trigger[$field . '_' . $code] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return $default;
    }
}
