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

/**
 * An export of a privacy request (PrivacyRequest::export()) as the rows that PrestaShop's GDPR
 * module prints: a list of flat rows, each a map of column name to value. The first column of
 * every row says what the row is. No SQL and no PrestaShop class: every word goes through the
 * callable that translates an EN string.
 *
 * The answer of a Campaign Intelligence connection is one row whose content is the engine's
 * answer as JSON, or one plain line where the engine holds nothing or did not answer.
 */
final class ExportRows
{
    /**
     * @param array{store: array<string, mixed>, campaign_intelligence: list<array<string, mixed>>} $export
     * @param callable(string): string $translate
     *
     * @return list<array<string, string|int>>
     */
    public static function of(array $export, callable $translate): array
    {
        $data = $translate('Data');
        $status = $translate('Status');
        $date = $translate('Date');
        $rows = [];
        foreach ($export['store']['queue'] as $row) {
            $rows[] = [
                $data => $translate('Queued message'),
                $translate('Type') => $row['type'],
                $status => $row['status'],
                $date => $row['created_at'],
            ];
        }
        foreach ($export['store']['carts'] as $row) {
            $rows[] = [
                $data => $translate('Abandoned cart'),
                $translate('Cart') => $row['cart'],
                $status => $row['status'],
                $translate('Last change') => $row['last_activity_at'],
                $translate('Reminder sent') => $row['reminded_at'],
            ];
        }
        foreach ($export['store']['attribution'] as $row) {
            $rows[] = [
                $data => $translate('Order attribution'),
                $translate('Order') => $row['order'],
                $translate('Recommendation') => $row['recommendation'],
                $translate('Context') => $row['context'],
                $translate('Visitor token') => $row['visitor_token'],
                $date => $row['created_at'],
            ];
        }
        foreach ($export['store']['cart_attribution'] as $row) {
            $rows[] = [
                $data => $translate('Cart attribution'),
                $translate('Cart') => $row['cart'],
                $translate('Recommendation') => $row['recommendation'],
                $translate('Context') => $row['context'],
                $translate('Visitor token') => $row['visitor_token'],
                $date => $row['updated_at'],
            ];
        }
        foreach ($export['store']['codes'] as $row) {
            $rows[] = [
                $data => $translate('Discount code'),
                $translate('Code') => $row['code'],
                $translate('Created') => $row['created_at'],
                $translate('Valid until') => $row['expires_at'],
                $translate('Used') => $translate($row['used'] ? 'Yes' : 'No'),
            ];
        }
        foreach ($export['store']['import_refusals'] ?? [] as $row) {
            $rows[] = [
                $data => $translate('Contacts Smaily refused'),
                $translate('Source') => $row['source'],
                $translate('Reason') => $row['reason'],
                $date => $row['updated_at'],
            ];
        }
        $preference = $export['store']['preference'];
        if ($preference !== null) {
            $rows[] = [
                $data => $translate('Personalization preference'),
                $translate('Personalized recommendations') => $translate($preference['opted_out'] ? 'Opted out' : 'Allowed'),
                $translate('Changed') => $preference['changed_at'],
                $translate('Source') => $preference['source'],
            ];
        }
        foreach ($export['campaign_intelligence'] as $answer) {
            if ($answer['status'] === PrivacyRequest::OK) {
                $content = (string) json_encode($answer['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $content = $translate($answer['status'] === PrivacyRequest::NO_DATA ? 'No data' : 'Not available');
            }
            $rows[] = [
                $data => $translate('Campaign Intelligence'),
                $translate('Connection') => $answer['connection'],
                $translate('Content') => $content,
            ];
        }

        return $rows;
    }
}
