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

namespace PrestaShop\Module\SmailyForPrestaShop\Migration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The files that an earlier release shipped and 3.0.0 does not. PrestaShop unpacks a new version
 * over the module's folder and deletes nothing, so an upgraded store keeps them: the 2.x settings
 * forms, templates and script, the classes of the 2.x contact sync and cart reminder (two of them
 * read the 2.x password as plain text), and what 1.6.x left in a store that came from it. 3.0.0
 * loads none of them. remove() deletes them from the module's folder.
 *
 * FILES is fixed. It was made once from the git tags: every path of the 2.2.0 release zip (the
 * tag's `src/` without `.gitignore`, `.php-cs-fixer.dist.php` and `phpstan.neon`, as its release
 * workflow packed it; 2.0.0 to 2.1.2 shipped no path beyond those) and every path of the 1.6.2 zip
 * (the tag's `src/`; 1.6.0 shipped no path beyond those), minus every path of the 3.0.0 zip
 * (`composer package`). No path under `vendor/` is listed, and none under `translations/`: the
 * `translations/et.php` of 1.6.x is the file that PrestaShop writes a merchant's own translations
 * to. docs/MARKETPLACE.md (rule 33) holds the commands.
 */
final class LegacyFiles
{
    public const FILES = [
        'controllers/admin/AdminSmailyforprestashopAjaxController.php',
        'controllers/admin/index.php',
        'lib/Api.php',
        'lib/Logger.php',
        'lib/Rss.php',
        'lib/index.php',
        'overrides/index.php',
        'overrides/ps_emailsubscription.php',
        'src/Controller/AbandonedCartController.php',
        'src/Controller/CustomerSyncController.php',
        'src/Controller/ModuleConfigurationController.php',
        'src/Controller/OptInController.php',
        'src/Form/AbandonedCartFormDataConfiguration.php',
        'src/Form/AbandonedCartFormType.php',
        'src/Form/AccountConfigurationFormType.php',
        'src/Form/AccountFormDataConfiguration.php',
        'src/Form/ChoiceProvider/Autoresponder.php',
        'src/Form/ChoiceProvider/ProductCategory.php',
        'src/Form/ChoiceProvider/index.php',
        'src/Form/CustomerSyncFormDataConfiguration.php',
        'src/Form/CustomerSyncFormType.php',
        'src/Form/FormDataProvider.php',
        'src/Form/RssFeedFormDataConfiguration.php',
        'src/Form/RssFeedFormType.php',
        'src/Form/index.php',
        'src/Model/AbandonedCart.php',
        'src/Model/AbandonedCartCollection.php',
        'src/Model/AbandonedCartProduct.php',
        'src/Model/SentCart.php',
        'src/Model/Subscriber.php',
        'src/Model/SubscriberCollection.php',
        'upgrade/Upgrade-1.1.0.php',
        'upgrade/Upgrade-1.3.0.php',
        'upgrade/Upgrade-1.5.0.php',
        'upgrade/Upgrade-1.6.0.php',
        'views/css/smaily_newsletter.css',
        'views/js/smaily-admin.js',
        'views/js/smaily_module.js',
        'views/templates/admin/abandonedCartConfiguration.html.twig',
        'views/templates/admin/accountConfiguration.html.twig',
        'views/templates/admin/configuration.html.twig',
        'views/templates/admin/customerSyncConfiguration.html.twig',
        'views/templates/admin/rssFeedConfiguration.html.twig',
        'views/templates/admin/smaily_configure.tpl',
        'views/templates/hook/index.php',
        'views/templates/hook/smaily_blocknewsletter.tpl',
        'views/templates/hook/smaily_blocknewsletter_column.tpl',
    ];

    /**
     * The folders of those releases that 3.0.0 does not have, a folder after the folders in it.
     * Each goes when it is empty once the files are deleted.
     */
    public const DIRECTORIES = [
        'controllers/admin',
        'lib',
        'overrides',
        'src/Form/ChoiceProvider',
        'src/Form',
        'views/templates/hook',
    ];

    private string $moduleDir;

    /**
     * @param string $moduleDir the module's folder; _PS_MODULE_DIR_ . 'smailyforprestashop' in a shop
     */
    public function __construct(string $moduleDir)
    {
        $this->moduleDir = $moduleDir;
    }

    /**
     * Deletes every listed file that the module's folder holds, then every listed folder that is
     * empty. Only a regular file at its listed place is deleted: a link, and a file under a folder
     * that is a link, lead out of the module's folder and stay. Nothing else is touched, and
     * nothing is thrown.
     *
     * @return list<string> the listed files that could not be deleted, relative to the module's folder
     */
    public function remove(): array
    {
        $root = realpath($this->moduleDir);
        if ($root === false) {
            return [];
        }
        $left = [];
        foreach (self::FILES as $path) {
            $file = $root . '/' . $path;
            if (!is_file($file) || realpath($file) !== $file) {
                continue;
            }
            if (!self::attempt(static fn (): bool => is_writable(dirname($file)) && unlink($file))) {
                $left[] = $path;
            }
        }
        foreach (self::DIRECTORIES as $path) {
            $dir = $root . '/' . $path;
            self::attempt(static fn (): bool => is_dir($dir) && realpath($dir) === $dir
                && scandir($dir) === ['.', '..'] && is_writable(dirname($dir)) && rmdir($dir));
        }

        return $left;
    }

    /**
     * @param callable(): bool $removal
     */
    private static function attempt(callable $removal): bool
    {
        try {
            return $removal();
        } catch (\Throwable $error) {
            // With debug mode on, PrestaShop turns the warning of a refused removal into an exception.
            return false;
        }
    }
}
