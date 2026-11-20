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
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\AuthenticationException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\InvalidSubdomainException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\PlanBlockedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\RequestRefusedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Asks Smaily once whether it accepts a subdomain, username and password, as Magento's
 * validateCredentials() does (one read of the workflow list), and words the answer as Magento's
 * TestSmaily does: refused credentials, a package without API access, a subdomain that Smaily
 * does not know, or Smaily out of reach. Nothing is stored or logged; the password never enters
 * an answer.
 */
final class SmailyCheck
{
    private Transport $transport;

    private TranslatorInterface $translator;

    public function __construct(Transport $transport, TranslatorInterface $translator)
    {
        $this->transport = $transport;
        $this->translator = $translator;
    }

    /**
     * @return array{connected: bool, error: ?string}
     */
    public function check(string $subdomain, string $username, string $password): array
    {
        try {
            (new Client($this->transport, $subdomain, $username, $password))->get(Client::ENDPOINT_AUTORESPONDER);

            return ['connected' => true, 'error' => null];
        } catch (InvalidSubdomainException $exception) {
            $error = $this->trans('The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.');
        } catch (PlanBlockedException $exception) {
            $error = $this->trans('Smaily refused the request because this account\'s package does not include API access. Upgrade the package in Smaily to connect — until then the credentials cannot be checked at all.');
        } catch (AuthenticationException $exception) {
            $error = $this->trans('Smaily rejected these credentials. Check the subdomain, username and password.');
        } catch (RequestRefusedException $exception) {
            $error = $exception->getHttpStatus() === 404
                ? $this->trans('Smaily does not know this subdomain. Check the subdomain.')
                : $this->trans('Could not reach Smaily: %error%', ['%error%' => $exception->getMessage()]);
        } catch (SmailyClientException $exception) {
            // The client's message names the HTTP status or the network error, never a credential.
            $error = $this->trans('Could not reach Smaily: %error%', ['%error%' => $exception->getMessage()]);
        }

        return ['connected' => false, 'error' => $error];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, BaseController::DOMAIN);
    }
}
