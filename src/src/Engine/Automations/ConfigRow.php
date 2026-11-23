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
 * One row of the Automations tab's card as the engine takes it at a save (contract §13): all eight
 * keys, as the Magento connector's ConfigRowNormalizer builds them.
 *
 * A save never drops a workflow that the page could not show as a choice:
 * - a per-language map, which another connector on the same engine store saved, is kept whole
 *   while the merchant did not pick another workflow here, or while its workflow of no language
 *   is not in the list (the empty value is then no decision to clear it);
 * - a single workflow that the list does not offer (it was deleted in Smaily, or the list could
 *   not be loaded) is kept where the page posts none. A workflow that the list offers and the
 *   merchant cleared is cleared.
 */
final class ConfigRow
{
    public const MAX_COOLDOWN_DAYS = 365;

    public const MAX_DAILY_CAP = 100000;

    public const MAX_TEST_EMAILS = 50;

    /**
     * @param array<string, mixed> $data the row as the page posts it: `enabled`, `test_mode`,
     *                                   `workflow_id`, `cooldown_days`, `daily_cap`, `test_emails`
     *                                   (separated by commas), and the `language_mode` and
     *                                   `original_map` that the page was drawn with
     * @param list<string> $offered the workflow ids of the Smaily list now; none when the list
     *                              could not be loaded, and every stored id then counts as missing
     *
     * @return array{trigger_key: string, enabled: bool, language_mode: string, automation_map: array<string, string>, cooldown_days: int, daily_cap: int|null, test_mode: bool, test_emails: list<string>}
     */
    public static function normalize(string $triggerKey, array $data, array $offered): array
    {
        $workflowId = is_scalar($data['workflow_id'] ?? null) ? trim((string) $data['workflow_id']) : '';
        if (!ctype_digit($workflowId)) {
            $workflowId = '';
        }
        $original = AutomationList::map($data['original_map'] ?? null);
        $storedSingle = $original['id'] ?? '';
        $storedFallback = $original['fallback'] ?? '';

        if (($data['language_mode'] ?? null) === 'per_language'
            && ($workflowId === $storedFallback || self::isMissing($workflowId, $storedFallback, $offered))) {
            $languageMode = 'per_language';
            $map = $original;
        } elseif (self::isMissing($workflowId, $storedSingle, $offered)) {
            $languageMode = 'single';
            $map = ['id' => $storedSingle];
        } else {
            $languageMode = 'single';
            $map = $workflowId !== '' ? ['id' => $workflowId] : [];
        }

        $dailyCap = is_scalar($data['daily_cap'] ?? null) ? trim((string) $data['daily_cap']) : '';
        $emails = array_values(array_filter(
            array_map('trim', explode(',', is_string($data['test_emails'] ?? null) ? $data['test_emails'] : '')),
            static fn (string $email): bool => $email !== ''
        ));

        return [
            'trigger_key' => $triggerKey,
            'enabled' => !empty($data['enabled']),
            'language_mode' => $languageMode,
            'automation_map' => $map,
            'cooldown_days' => max(1, min(self::MAX_COOLDOWN_DAYS, (int) ($data['cooldown_days'] ?? AutomationList::DEFAULT_COOLDOWN_DAYS))),
            'daily_cap' => $dailyCap === '' ? null : max(1, min(self::MAX_DAILY_CAP, (int) $dailyCap)),
            'test_mode' => !empty($data['test_mode']),
            'test_emails' => array_slice($emails, 0, self::MAX_TEST_EMAILS),
        ];
    }

    /**
     * Whether a stored workflow stays although the page posts none: the list does not offer it.
     *
     * @param list<string> $offered
     */
    private static function isMissing(string $posted, string $stored, array $offered): bool
    {
        return $posted === '' && $stored !== '' && !in_array($stored, $offered, true);
    }
}
