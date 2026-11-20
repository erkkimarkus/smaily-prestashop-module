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

namespace PrestaShop\Module\SmailyForPrestaShop\Update;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\UpdateSettings;

/**
 * The admin notice that a newer version of the module is out.
 *
 * The daily update check records the latest release; the admin reads current() on a page request
 * (no HTTP, no query: the state comes with the configuration the request loaded) and calls
 * dismiss() when the merchant closes the notice. A dismissal holds for that version and every
 * older one, for every admin; a later release shows again.
 */
final class UpdateNotice
{
    private UpdateSettings $settings;

    private string $installedVersion;

    /**
     * @param string $installedVersion the version of the module code that runs
     */
    public function __construct(UpdateSettings $settings, string $installedVersion)
    {
        $this->settings = $settings;
        $this->installedVersion = $installedVersion;
    }

    /**
     * The release to tell the merchant about, or null: a notice exists only while a release newer
     * than the installed module is known and its version was not dismissed. Both URLs point to the
     * official repository.
     *
     * @return array{version: string, download_url: string, release_url: string}|null
     */
    public function current(): ?array
    {
        $state = $this->settings->state();
        $version = $state['version'] ?? null;
        $downloadUrl = $state['download_url'] ?? null;
        $releaseUrl = $state['release_url'] ?? null;
        $dismissed = $state['dismissed'] ?? null;
        if (!is_string($version) || Version::fromTag($version) !== $version
            || !is_string($downloadUrl) || !LatestRelease::isOfficialUrl($downloadUrl)
            || !is_string($releaseUrl) || !LatestRelease::isOfficialUrl($releaseUrl)
            || $this->installedVersion === ''
            || !Version::isNewer($version, $this->installedVersion)
            || (is_string($dismissed) && !Version::isNewer($version, $dismissed))
        ) {
            return null;
        }

        return ['version' => $version, 'download_url' => $downloadUrl, 'release_url' => $releaseUrl];
    }

    /**
     * Closes the notice for $version (as current() gave it; a leading "v" is tolerated). Anything
     * that is no plain version number changes nothing.
     */
    public function dismiss(string $version): void
    {
        $version = Version::fromTag($version);
        if ($version === null) {
            return;
        }
        $this->settings->save(['dismissed' => $version] + $this->settings->storedState());
    }

    /**
     * Records the latest release that a check found, and when; the dismissal stays.
     *
     * @param array{version: string, download_url: string, release_url: string} $release
     */
    public function record(array $release, int $checkedAt): void
    {
        $this->settings->save([
            'version' => $release['version'],
            'download_url' => $release['download_url'],
            'release_url' => $release['release_url'],
            'checked_at' => $checkedAt,
            'dismissed' => $this->settings->storedState()['dismissed'] ?? null,
        ]);
    }
}
