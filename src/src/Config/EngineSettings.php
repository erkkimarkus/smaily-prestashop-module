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

namespace PrestaShop\Module\SmailyForPrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Settings of the Intelligence section.
 */
final class EngineSettings extends Settings
{
    /**
     * JSON object: the connection prefix that the connection of each id_shop held when it was
     * disconnected. A row of the connection table goes at a disconnect, and the next setup exchange
     * of that shop still has to report the prefix (contract §1 `connection.previous_prefix`).
     */
    public const PREVIOUS_PREFIXES = self::PREFIX . 'ENGINE_PREVIOUS_PREFIXES';

    /**
     * The prefix that the last disconnected connection of an id_shop held, exactly as the engine
     * gave it ('' for a connection without one); null when none was disconnected.
     */
    public function previousPrefix(int $idShop): ?string
    {
        $prefix = $this->getJson(self::PREVIOUS_PREFIXES)[$idShop] ?? null;

        return is_string($prefix) ? $prefix : null;
    }

    public function rememberPrefix(int $idShop, string $prefix): void
    {
        $prefixes = $this->getJson(self::PREVIOUS_PREFIXES);
        $prefixes[$idShop] = $prefix;
        // PrestaShop strips tags from a configuration value. The prefix is the engine's and is
        // kept as it came, so the JSON spells every character that a tag needs as an escape.
        $this->set(self::PREVIOUS_PREFIXES, (string) json_encode($prefixes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    /**
     * `1` once the merchant confirmed that the store's cookie banner covers Smaily's browse
     * tracking and recommendations (ARCHITECTURE section 9). Global, or a shop's own under separate
     * stores. Nothing stored is off: an install and an upgrade store nothing.
     */
    public const CONSENT_CONFIRMED = self::PREFIX . 'CONSENT_CONFIRMED';

    /**
     * Whether the merchant confirmed the cookie banner for the visitors of a setup.
     *
     * @param int $idShop the shop under separate stores, else 0 (Engine\Connections::keyOf())
     */
    public function consentConfirmed(int $idShop): bool
    {
        return $this->get(self::CONSENT_CONFIRMED, $idShop) === '1';
    }

    /**
     * @param int $idShop the shop under separate stores, else 0 (Engine\Connections::keyOf())
     */
    public function setConsentConfirmed(bool $confirmed, int $idShop): bool
    {
        return $this->set(self::CONSENT_CONFIRMED, $confirmed ? '1' : '0', $idShop);
    }
}
