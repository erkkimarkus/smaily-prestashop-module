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
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Intelligence panel (Initial setup step 4, Settings > Intelligence): the Campaign
 * Intelligence connection of the page's scope, the merchant's confirmation that the store's
 * cookie banner covers Smaily tracking, and the three imports, read and changed by this one class.
 *
 * The scope is the Initial setup's (SetupScope): the installation-wide connection in one store,
 * the selected shop's own in separate stores, and nothing while no shop is selected there. A
 * connection starts its catalog, customers and orders imports by itself (owner, 2026-11-23); they
 * run from the next background run on, so "Hold back the import" before it sends nothing.
 *
 * No answer carries the engine's text or a key: a refused link and a refused connection are
 * worded here, by the error code alone.
 */
final class IntelligencePanel
{
    public const STATE_CONNECTED = 'connected';

    public const STATE_NOT_ACTIVE = 'not_active';

    public const STATE_KEY_REFUSED = 'key_refused';

    public const STATE_UPDATE_REQUIRED = 'update_required';

    private Connections $connections;

    private Setup $setup;

    private HealthCheck $health;

    private Disconnector $disconnector;

    private HistoryJobs $jobs;

    private EngineSettings $settings;

    private TranslatorInterface $translator;

    public function __construct(Connections $connections, Setup $setup, HealthCheck $health, Disconnector $disconnector, HistoryJobs $jobs, EngineSettings $settings, TranslatorInterface $translator)
    {
        $this->connections = $connections;
        $this->setup = $setup;
        $this->health = $health;
        $this->disconnector = $disconnector;
        $this->jobs = $jobs;
        $this->settings = $settings;
        $this->translator = $translator;
    }

    /**
     * @return array{connected: bool, name: string, engine_version: string, state: string, account_url: string, consent: bool, imports: array<string, array<string, mixed>|null>|null}|null null when the scope saves nothing
     */
    public function values(SetupScope $scope): ?array
    {
        $shop = $scope->targetShop();
        if ($shop === null) {
            return null;
        }
        $connection = $this->connections->find($shop);
        $settings = $connection === null ? [] : $connection->settings();

        return [
            'connected' => $connection !== null,
            'name' => is_string($settings['tenant_name'] ?? null) ? $settings['tenant_name'] : '',
            'engine_version' => is_string($settings['engine_version'] ?? null) ? $settings['engine_version'] : '',
            'state' => $connection === null ? '' : self::state($connection),
            'account_url' => $connection !== null && $connection->smailySubdomain() !== '' ? 'https://' . $connection->smailySubdomain() . '.sendsmaily.net' : '',
            'consent' => $this->settings->consentConfirmed($shop),
            'imports' => $connection === null ? null : $this->jobs->progress($connection),
        ];
    }

    /**
     * Exchanges the pasted setup link for a connection and starts the three imports.
     *
     * @return array{error: string|null, values: array<string, mixed>|null} the reason in the module's own words, or the panel's new values
     */
    public function connect(SetupScope $scope, string $link): array
    {
        $shop = $scope->targetShop();
        if ($shop === null) {
            return ['error' => $this->separateStores(), 'values' => null];
        }
        if (trim($link) === '') {
            return ['error' => $this->trans('Paste the setup URL or token from Smaily first.'), 'values' => null];
        }
        try {
            Setup::parseLink($link);
        } catch (EngineException $error) {
            return ['error' => $this->trans('The setup URL must be an https address on intelligence.smaily.com.'), 'values' => null];
        }
        try {
            $connection = $this->setup->connect($shop, $link);
        } catch (EngineException $error) {
            return ['error' => $this->refusal($error), 'values' => null];
        }
        $this->jobs->startAll($connection);

        return ['error' => null, 'values' => $this->values($scope)];
    }

    /**
     * "Check again": asks the engine about the scope's connection now.
     *
     * @return array{message: string, values: array<string, mixed>|null} a sentence when the engine refuses or does not answer
     */
    public function check(SetupScope $scope): array
    {
        $connection = $this->connection($scope);
        $message = '';
        if ($connection !== null) {
            $status = $this->health->check($connection);
            $connection = $this->connection($scope);
            if ($status === HealthCheck::UNREACHABLE) {
                $message = $this->trans('Could not reach Campaign Intelligence.');
            } elseif ($connection !== null && self::state($connection) === self::STATE_NOT_ACTIVE) {
                $message = $this->trans('Campaign Intelligence still reports that this account is not active.');
            }
        }

        return ['message' => $message, 'values' => $this->values($scope)];
    }

    /**
     * "Disconnect": tells the engine once and removes the stored connection, whatever it answered.
     * The imports of the connection end at the next background run.
     *
     * @return array<string, mixed>|null the panel's new values
     */
    public function disconnect(SetupScope $scope): ?array
    {
        $connection = $this->connection($scope);
        if ($connection !== null) {
            $this->disconnector->disconnect($connection);
        }

        return $this->values($scope);
    }

    /**
     * Saves the posted `consent`: the merchant confirms that the store's cookie banner covers
     * Smaily tracking (ARCHITECTURE section 9).
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    public function save(SetupScope $scope, array $data): array
    {
        $shop = $scope->targetShop();
        if ($shop === null) {
            return [['field' => '', 'message' => $this->separateStores()]];
        }
        $this->settings->setConsentConfirmed(!empty($data['consent']), $shop);

        return [];
    }

    /**
     * Starts an import from the beginning (`start`), or cancels it and the imports that wait
     * behind it (`cancel`); "Hold back the import" is a cancel of the catalog import.
     *
     * @return array<string, mixed>|null the panel's new values; null for an unknown action or entity, or no connection
     */
    public function import(SetupScope $scope, string $entity, string $action): ?array
    {
        $connection = $this->connection($scope);
        if ($connection === null || !in_array($entity, HistoryJobs::ENTITIES, true) || !in_array($action, ['start', 'cancel'], true)) {
            return null;
        }
        if ($action === 'start') {
            $this->jobs->start($connection, $entity);
        } else {
            $this->jobs->cancel($connection, $entity);
        }

        return $this->values($scope);
    }

    private function connection(SetupScope $scope): ?Connection
    {
        $shop = $scope->targetShop();

        return $shop === null ? null : $this->connections->find($shop);
    }

    /**
     * What the page shows for a stored connection, from the last health check: connected (also
     * while the engine does not answer), or why the engine refuses it.
     */
    private static function state(Connection $connection): string
    {
        if ($connection->apiKey() === '') {
            return self::STATE_KEY_REFUSED;
        }
        if ($connection->refusedAt() === '') {
            return self::STATE_CONNECTED;
        }
        switch ($connection->health()['error']) {
            case 'tenant_inactive':
                return self::STATE_NOT_ACTIVE;
            case 'plugin_update_required':
                return self::STATE_UPDATE_REQUIRED;
            default:
                return self::STATE_KEY_REFUSED;
        }
    }

    /** Why a setup link was not taken, in the module's own words. */
    private function refusal(EngineException $error): string
    {
        switch ($error->errorCode()) {
            case 'setup_token_expired_or_used':
                return $this->trans('This setup link has already been used. Ask Smaily for a new one.');
            case 'setup_token_not_found':
                return $this->trans('Setup token not recognised. Verify the URL is correct.');
            case Setup::ACCOUNT_MISMATCH:
                return $this->trans('This setup link is for the Campaign Intelligence store of another Smaily account, so the connection was not saved. The link is now used up: ask Smaily for a new setup link for this store’s Smaily account.');
            case Client::HOST_NOT_ALLOWED:
                return $this->trans('The engine answered with an address that is not an https address on intelligence.smaily.com, so the connection was not saved.');
            case 'plugin_update_required':
                return $this->trans('Campaign Intelligence needs a newer version of Smaily Connect. Update the module and try again with a new setup link.');
        }

        return $error instanceof EngineTransportException
            ? $this->trans('Could not reach Campaign Intelligence. Try again in a few minutes.')
            : $this->trans('Campaign Intelligence did not accept this setup link, so the connection was not saved. Ask Smaily for a new one.');
    }

    private function separateStores(): string
    {
        return $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.');
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], BaseController::DOMAIN);
    }
}
