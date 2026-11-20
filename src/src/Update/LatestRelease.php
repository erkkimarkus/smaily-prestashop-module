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

use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * The latest release of the module's official repository on GitHub.
 *
 * The request carries no store data: no shop address or name, no account, no cookie. Its only
 * header of the module's own is a fixed User-Agent that names the module and its version (GitHub
 * refuses a request without one). GitHub's "latest release" is the newest release that is neither
 * a draft nor a pre-release.
 *
 * An answer is taken whole or not at all: its tag must be a plain version number, and every URL
 * taken from it must point to the official repository on github.com or api.github.com, so that a
 * tampered answer cannot make the admin link elsewhere. The module never downloads the release.
 */
final class LatestRelease
{
    public const API_BASE = 'https://api.github.com';

    public const PATH = '/repos/sendsmaily/smaily-prestashop-module/releases/latest';

    /** Seconds the request may take as a whole, so that a slow GitHub does not hold the tick. */
    public const TIMEOUT_SECONDS = 5;

    /**
     * The URLs of the official repository: https, the exact host, the repository's own path, and
     * nothing that can climb out of it (no "..", query, fragment, port or credentials).
     */
    private const OFFICIAL_URLS = [
        '~^https://github\.com/sendsmaily/smaily-prestashop-module/[A-Za-z0-9._%/+-]+$~D',
        '~^https://api\.github\.com/repos/sendsmaily/smaily-prestashop-module/[A-Za-z0-9._%/+-]+$~D',
    ];

    private Transport $transport;

    private string $userAgent;

    private string $apiBase;

    /**
     * @param string $moduleVersion the installed module's version, which the User-Agent names
     * @param string $apiBase the GitHub API's address; the tests point it at a local mock
     */
    public function __construct(Transport $transport, string $moduleVersion, string $apiBase = self::API_BASE)
    {
        $this->transport = $transport;
        $this->userAgent = 'SmailyForPrestaShop/' . $moduleVersion;
        $this->apiBase = $apiBase;
    }

    /**
     * @return array{version: string, download_url: string, release_url: string}
     *
     * @throws NetworkException when no answer arrives
     * @throws \UnexpectedValueException when the answer is not HTTP 200 or not a usable release
     */
    public function fetch(): array
    {
        $response = $this->transport->request('GET', $this->apiBase . self::PATH, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/vnd.github+json',
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
        ]);
        if ($response->status() !== 200) {
            throw new \UnexpectedValueException(sprintf('GitHub answered HTTP %d.', $response->status()));
        }

        return self::parse($response->body());
    }

    /**
     * Reads a release as GitHub's API answers it: the version of its tag, its page, and the
     * download URL of its first .zip asset (the page when it has none).
     *
     * @return array{version: string, download_url: string, release_url: string}
     *
     * @throws \UnexpectedValueException when the release is not usable
     */
    public static function parse(string $body): array
    {
        $release = json_decode($body, true);
        if (!is_array($release) || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false) {
            throw new \UnexpectedValueException('The answer is no published release.');
        }
        $version = is_string($release['tag_name'] ?? null) ? Version::fromTag($release['tag_name']) : null;
        if ($version === null) {
            throw new \UnexpectedValueException('The release tag is no plain version number.');
        }
        $releaseUrl = $release['html_url'] ?? null;
        if (!is_string($releaseUrl) || !self::isOfficialUrl($releaseUrl)) {
            throw new \UnexpectedValueException('The release page is not in the official repository.');
        }
        if (!is_array($release['assets'] ?? null)) {
            throw new \UnexpectedValueException('The release lists no assets.');
        }

        $downloadUrl = $releaseUrl;
        foreach ($release['assets'] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && preg_match('/\.zip$/iD', $asset['name']) === 1) {
                $downloadUrl = $asset['browser_download_url'] ?? null;
                if (!is_string($downloadUrl) || !self::isOfficialUrl($downloadUrl)) {
                    throw new \UnexpectedValueException('The release zip is not in the official repository.');
                }
                break;
            }
        }

        return ['version' => $version, 'download_url' => $downloadUrl, 'release_url' => $releaseUrl];
    }

    /**
     * Whether $url points into the official repository on github.com or api.github.com.
     */
    public static function isOfficialUrl(string $url): bool
    {
        if (strpos($url, '..') !== false) {
            return false;
        }
        foreach (self::OFFICIAL_URLS as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
