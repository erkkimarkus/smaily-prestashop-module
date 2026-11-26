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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Details for your developer" of an import card: the import's stored reason in the admin's
 * language, as the Log shows it (Log\FailureMessage), as `details` beside the progress. A stopped
 * import stores where it stopped (Queue\Failure::location()), as Magento's stopped import keeps
 * its ErrorLocation (CON-233); a reason stored before that shows as the Log shows it.
 */
final class ImportDetails
{
    /**
     * A contact import's progress (ContactImport::progress()), or null, with `details`.
     *
     * @param array<string, mixed>|null $progress
     *
     * @return array<string, mixed>|null
     */
    public static function contacts(?array $progress, TranslatorInterface $translator): ?array
    {
        return $progress === null ? null : $progress + ['details' => self::of($progress['last_error'], $translator)];
    }

    /**
     * The Campaign Intelligence imports (HistoryJobs::progress()), or null, each with `details`.
     *
     * @param array<string, array<string, mixed>|null>|null $imports
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    public static function intelligence(?array $imports, TranslatorInterface $translator): ?array
    {
        foreach ($imports ?? [] as $entity => $job) {
            if ($job !== null) {
                $imports[$entity]['details'] = self::of($job['reason'], $translator);
            }
        }

        return $imports;
    }

    private static function of(?string $reason, TranslatorInterface $translator): string
    {
        return FailureMessage::forDisplay(
            $reason,
            static fn (string $id, array $parameters = []): string => $translator->trans($id, $parameters, BaseController::DOMAIN),
            true
        );
    }
}
