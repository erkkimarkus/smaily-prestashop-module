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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sentence of each notice that the legacy migration left (Config\MigrationNotices): the
 * migration stores a code and its parameters, and the admin owns the wording.
 */
final class NoticeMessages
{
    private TranslatorInterface $translator;

    /** @var callable(int): string */
    private $shopName;

    /**
     * @param callable(int): string $shopName the name of a shop by its id
     */
    public function __construct(TranslatorInterface $translator, callable $shopName)
    {
        $this->translator = $translator;
        $this->shopName = $shopName;
    }

    /**
     * @param array{id: string, code: string, params: array<string, int|string>} $notice
     */
    public function text(array $notice): string
    {
        $params = $notice['params'];
        switch ($notice['code']) {
            case MigrationPlan::NOTICE_SETTINGS_MIGRATED:
                return $this->trans('Your settings from the previous version of the module were carried over. Review them under Settings, or re-run the initial setup — your saved configuration is untouched either way.');
            case MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF:
                return $this->trans('Contact synchronization stays off for %shop%: %reason% Switch it on under Settings > Contacts when you want it.', $this->leftOff($params));
            case MigrationPlan::NOTICE_WELCOME_LEFT_OFF:
                return $this->trans('The welcome automation stays off for %shop%: %reason% Switch it on under Settings > Automations when you want it.', $this->leftOff($params));
            case MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF:
                return $this->trans('The abandoned cart automation stays off for %shop%: %reason% Switch it on under Settings > Automations when you want it.', $this->leftOff($params));
            case MigrationPlan::NOTICE_CART_DELAY_CAPPED:
                return $this->trans('Abandoned-cart reminders: your waiting time was longer than a day. Reminders are never sent for a cart older than 24 hours, so the waiting time is now 1435 minutes. You can change it under Settings > Automations.');
            case MigrationPlan::NOTICE_FIELD_LIST_UNREADABLE:
                return ($params['key'] ?? '') === 'SMAILY_CART_SYNCRONIZE_ADDITIONAL'
                    ? $this->trans('The extra fields of the abandoned cart email could not be read and were not carried over. Choose them again under Settings > Automations.')
                    : $this->trans('The extra fields to sync with each contact could not be read and were not carried over. Choose them again under Settings > Contacts.');
            case MigrationPlan::NOTICE_CRON_TOKEN_REPLACED:
                return ($params['key'] ?? '') === TickSettings::CART_CRON_TOKEN
                    ? $this->trans('The abandoned cart cron URL had no token, so anyone could call it. It now has a new token, so the old crontab line for it no longer works. Use the one cron line under Dashboard > Background tasks > Show the cron line: it runs every task and replaces both of your old crontab lines.')
                    : $this->trans('The contact synchronization cron URL had no token, so anyone could call it. It now has a new token, so the old crontab line for it no longer works. Use the one cron line under Dashboard > Background tasks > Show the cron line: it runs every task and replaces both of your old crontab lines.');
            case MigrationPlan::NOTICE_CRON_SHOP_ASSUMED:
                return $this->trans('The previous version ran its cron jobs for one shop only. They now run for %shop%; set up the other shops under Settings.', ['%shop%' => $this->shop($params)]);
            case MigrationPlan::NOTICE_NEWSLETTER_BLOCK_REMOVED:
                return $this->trans('The newsletter block of version 1.6 is gone. Use PrestaShop\'s own newsletter block (ps_emailsubscription) instead.');
            case MigrationPlan::NOTICE_EMAILSUBSCRIPTION_EMAILS_CHANGED:
                return $this->trans('The previous version switched off the verification and confirmation emails of the newsletter block (ps_emailsubscription) and cleared its voucher. They stay as they are: switch them on again in that module if you want them.');
            case MigrationNotices::OVERRIDE_LEFT_IN_PLACE:
                return $this->trans('The file %path% was left in place: the previous version of this module did not ship it as it is now.', ['%path%' => (string) ($params['path'] ?? '')]);
            default:
                return $this->trans('The upgrade left a notice (%code%).', ['%code%' => $notice['code']]);
        }
    }

    /**
     * @param array<string, int|string> $params
     *
     * @return array<string, string>
     */
    private function leftOff(array $params): array
    {
        return ['%shop%' => $this->shop($params), '%reason%' => $this->reason((string) ($params['reason'] ?? ''))];
    }

    private function reason(string $reason): string
    {
        switch ($reason) {
            case MigrationPlan::REASON_SWITCH_NOT_READ:
                return $this->trans('the previous version did not run it, as it read another switch.');
            case MigrationPlan::REASON_NO_ACCOUNT:
                return $this->trans('no Smaily account was saved for it.');
            case MigrationPlan::REASON_NO_WORKFLOW:
                return $this->trans('no Smaily workflow was chosen for it.');
            case MigrationPlan::REASON_FIELD_LIST_NOT_JSON:
                return $this->trans('the previous version stopped with an error on its field list, so it sent nothing.');
            case MigrationPlan::REASON_NOT_DEFAULT_SHOP:
                return $this->trans('the previous version ran it for the default shop only.');
            case MigrationPlan::REASON_CARTS_NOT_REACHED:
                return $this->trans('the previous version did not reach the carts of this shop.');
            case MigrationPlan::REASON_OTHER_ACCOUNT:
                return $this->trans('the previous version sent it through the default shop\'s Smaily account.');
            default:
                return $this->trans('the previous version did not send it.');
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private function shop(array $params): string
    {
        return ($this->shopName)((int) ($params['id_shop'] ?? 0));
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $id, array $params = []): string
    {
        return $this->translator->trans($id, $params, BaseController::DOMAIN);
    }
}
