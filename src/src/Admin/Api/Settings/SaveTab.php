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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Saves one tab of Settings, as Magento's Settings saves a tab through its SaveStep: POST {"tab":
 * …, "data": {…}, "own": bool}. Connection, Contacts and Automations go through the Initial
 * setup's panels (Admin\Settings\TabSaver), RSS through Admin\Settings\RssPanel.
 *
 * - `connection`: answers `connected` and, when Smaily refused or could not be asked, `error`; the
 *   save stands either way.
 * - `contacts`: the Contacts settings.
 * - `automations`: the store automations' switches, workflows and cart delay.
 * - `rss`: the product feed URL builder's defaults.
 *
 * `own` is the tab's switch "Use its own … on this shop", which a shop selected in one store shows.
 * `returned` says that the save removed the shop's own values and `own_account_started` that it
 * removed the shop's own automations; the page then reloads.
 */
final class SaveTab extends AbstractSettingsAction
{
    protected function handle(Request $request, int $idShop)
    {
        $body = $this->body($request);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $own = !empty($body['own']);
        $settings = $this->settings();

        switch ((string) ($body['tab'] ?? '')) {
            case 'connection':
                $result = $settings->saver()->saveConnection($settings->context($idShop), $data, $own);
                if ($result['errors'] !== []) {
                    return $this->fieldErrors($result['errors']);
                }

                return [
                    'connected' => $result['connected'],
                    'error' => $result['error'],
                    'workflows_removed' => $result['workflows_removed'],
                    'returned' => $result['returned'],
                    'own_account_started' => $result['own_account_started'],
                ];
            case 'contacts':
            case 'automations':
                $saver = $settings->saver();
                $context = $settings->context($idShop);
                $result = $body['tab'] === 'contacts' ? $saver->saveContacts($context, $data, $own) : $saver->saveAutomations($context, $data, $own);

                return $result['errors'] === [] ? ['returned' => $result['returned']] : $this->fieldErrors($result['errors']);
            case 'rss':
                $errors = $settings->rss()->save($data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            default:
                return $this->error('unknown_tab', $this->trans('Saving failed.', BaseController::DOMAIN));
        }
    }
}
