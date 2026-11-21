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
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Connection panel (Initial setup step 1, Settings > Connection): the Smaily account and the
 * language mode of the page's scope (SetupScope), read and saved by this one class, as Magento's
 * WizardStepSaver::saveConnect() saves them.
 *
 * - A pasted URL or `name.sendsmaily.net` is reduced to the subdomain.
 * - An empty password keeps the stored one only for the account it belongs to: the same subdomain
 *   (in any case) and username. A changed account needs its password.
 * - Mode `a` (a Smaily account per language): one account per language of the scope, and the
 *   fallback account, whose credentials are also the account of no language (`''`). A language
 *   whose block is left empty has no account: its contacts go to the fallback account.
 * - Every other mode: the account of no language.
 * - Saving asks Smaily whether it accepts the account of no language. The answer never stops the
 *   save: a refused account is saved and reads "Not connected", as in Magento.
 * - A workflow belongs to the Smaily account it was picked from. Where a save puts another Smaily
 *   account (another subdomain) in the place of a stored one, the workflows picked from the stored
 *   one go: the mapping rows of that account's key, at the scope and at every shop that sends
 *   through the scope's account. So no automation is posted to an account with another account's
 *   workflow id: it sends nothing (`no_workflow`) until a workflow of the new account is picked.
 *   Mode `a` does this to the account of no language, which becomes the fallback account; the
 *   page asks before it.
 *
 * Nothing here returns a password: values() says only whether one is stored.
 */
final class ConnectionPanel
{
    private const PLAIN_SUBDOMAIN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    private AccountStore $accounts;

    private StoreLayout $layout;

    private SmailyCheck $check;

    private TranslatorInterface $translator;

    private MappingRepository $mappings;

    /** @var callable(): list<int> */
    private $shops;

    /**
     * @param callable(): list<int> $shops the ids of the store's shops
     */
    public function __construct(AccountStore $accounts, StoreLayout $layout, SmailyCheck $check, TranslatorInterface $translator, MappingRepository $mappings, callable $shops)
    {
        $this->accounts = $accounts;
        $this->layout = $layout;
        $this->check = $check;
        $this->translator = $translator;
        $this->mappings = $mappings;
        $this->shops = $shops;
    }

    /**
     * Reduces "https://demo.sendsmaily.net/…", "demo.sendsmaily.net" or "demo" to "demo"
     * (Magento's SubdomainNormalizer).
     */
    public static function normalizeSubdomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $value = $host;
        }
        $value = (string) preg_replace('/\.sendsmaily\.net.*$/i', '', $value);

        return strtolower(trim($value, " \t\n\r\0\x0B/."));
    }

    /**
     * The panel's values for the scope's target shop; null when the scope saves nothing.
     *
     * @return array{subdomain: string, username: string, has_password: bool, language_mode: string, fallback_language: string, accounts: array<string, array{subdomain: string, username: string, has_password: bool}>}|null
     */
    public function values(SetupScope $scope): ?array
    {
        $target = $scope->targetShop();
        if ($target === null) {
            return null;
        }
        $account = $this->accounts->find($target, '');
        $mode = $this->layout->languageMode($target);
        if (!in_array($mode, $scope->languageModes(), true)) {
            $mode = StoreLayout::MODE_SINGLE;
        }
        $perLanguage = [];
        foreach (array_keys($scope->languages()) as $iso) {
            $own = $this->accounts->find($target, $iso);
            $perLanguage[$iso] = [
                'subdomain' => $own !== null ? $own->subdomain() : '',
                'username' => $own !== null ? $own->username() : '',
                'has_password' => $own !== null && $own->password() !== '',
            ];
        }

        return [
            'subdomain' => $account !== null ? $account->subdomain() : '',
            'username' => $account !== null ? $account->username() : '',
            'has_password' => $account !== null && $account->password() !== '',
            'language_mode' => $mode,
            'fallback_language' => $this->layout->fallbackLanguage($target),
            'accounts' => $perLanguage,
        ];
    }

    /**
     * Checks and saves the posted connection: `subdomain`, `username`, `password`,
     * `language_mode`, and in mode `a` `fallback_language` and `accounts` (ISO code =>
     * {subdomain, username, password}). Nothing is written while any field is at fault.
     *
     * @param array<string, mixed> $data
     *
     * @return array{errors: list<array{field: string, message: string}>, connected: bool, error: ?string, workflows_removed: bool} `workflows_removed`: the save replaced a Smaily account whose workflows the automations held
     */
    public function save(SetupScope $scope, array $data): array
    {
        $refused = $this->scopeErrors($scope);
        if ($refused !== []) {
            return ['errors' => $refused, 'connected' => false, 'error' => null, 'workflows_removed' => false];
        }
        $target = (int) $scope->targetShop();
        $mode = (string) ($data['language_mode'] ?? StoreLayout::MODE_SINGLE);
        if (!in_array($mode, $scope->languageModes(), true)) {
            $mode = StoreLayout::MODE_SINGLE;
        }

        if ($mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
            [$errors, $accounts, $fallback] = $this->perLanguageAccounts($scope, $data, $target);
        } else {
            [$errors, $account] = $this->account(
                $data,
                $this->accounts->find($target, ''),
                '',
                $this->trans('The subdomain or username changed — enter the password of this account.')
            );
            $accounts = $account !== null ? ['' => $account] : [];
            $fallback = '';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'connected' => false, 'error' => null, 'workflows_removed' => false];
        }

        $removed = false;
        foreach ($accounts as $language => $credentials) {
            $stored = $this->accounts->find($target, (string) $language);
            if ($stored !== null && strcasecmp(trim($stored->subdomain()), $credentials[0]) !== 0) {
                $removed = $this->removeWorkflowsOf($target, (string) $language) || $removed;
            }
            $this->accounts->save(new Account($target, (string) $language, $credentials[0], $credentials[1], $credentials[2]));
        }
        if ($mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
            // A language left empty has no account of its own any more: the fallback takes it.
            foreach (array_keys($scope->languages()) as $iso) {
                if (!isset($accounts[$iso])) {
                    $this->accounts->delete($target, $iso);
                }
            }
            $this->layout->setFallbackLanguage($fallback, $target);
        }
        $this->layout->setLanguageMode($mode, $target);

        $check = $this->check->check($accounts[''][0], $accounts[''][1], $accounts[''][2]);

        return ['errors' => [], 'connected' => $check['connected'], 'error' => $check['error'], 'workflows_removed' => $removed];
    }

    /**
     * Removes the workflows that were picked from the stored account of a language ('' = no
     * language) at the scope: the mapping rows of its account key there, and at every shop that
     * sends that key through the shared account (Automation\Router::accountFor()). Returns whether
     * any row held one.
     */
    private function removeWorkflowsOf(int $target, string $language): bool
    {
        $accountKey = $language === '' ? MappingRepository::ACCOUNT_DEFAULT : $language;
        $scopes = [$target];
        if ($target === 0) {
            foreach (($this->shops)() as $idShop) {
                if ($this->accounts->find($idShop, $language) === null) {
                    $scopes[] = $idShop;
                }
            }
        }
        $removed = false;
        foreach ($scopes as $idShop) {
            foreach ($this->mappings->rows($idShop) as $row) {
                $removed = $removed || ($row['account_key'] === $accountKey && $row['workflow_id'] !== null);
            }
            $this->mappings->removeWorkflows($idShop, $accountKey);
        }

        return $removed;
    }

    /**
     * Tests the typed credentials without saving them. `language` names the stored account
     * (`''`, or an ISO code in mode `a`) whose password an empty password field keeps, when the
     * subdomain and username are the stored ones.
     *
     * @param array<string, mixed> $data
     *
     * @return array{errors: list<array{field: string, message: string}>, connected: bool, error: ?string}
     */
    public function test(SetupScope $scope, array $data): array
    {
        $target = $scope->targetShop();
        $language = (string) ($data['language'] ?? '');
        if ($language !== '' && !isset($scope->languages()[$language])) {
            $language = '';
        }
        $stored = $target !== null ? $this->accounts->find($target, $language) : null;
        $subdomain = self::normalizeSubdomain((string) ($data['subdomain'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($subdomain !== '' && preg_match(self::PLAIN_SUBDOMAIN, $subdomain) !== 1) {
            return $this->testError('subdomain', $this->trans('The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.'));
        }
        if ($password === '' && $stored !== null && self::sameAccount($stored, $subdomain, $username)) {
            $password = $stored->password();
        }
        if ($subdomain === '' || $username === '' || $password === '') {
            $message = $this->trans('Please fill in the subdomain, username and password.');
            $errors = [];
            foreach (['subdomain' => $subdomain, 'username' => $username, 'password' => $password] as $field => $value) {
                if ($value === '') {
                    $errors[] = ['field' => $field, 'message' => $message];
                }
            }

            return ['errors' => $errors, 'connected' => false, 'error' => $message];
        }

        return ['errors' => []] + $this->check->check($subdomain, $username, $password);
    }

    /**
     * Asks Smaily about the stored account of a language (`''`: the scope's own account).
     *
     * @return array{connected: bool, error: ?string, subdomain: string}|null null when none is stored
     */
    public function checkStored(SetupScope $scope, string $language): ?array
    {
        $target = $scope->targetShop();
        $account = $target !== null ? $this->accounts->find($target, $language) : null;
        if ($account === null) {
            return null;
        }

        return ['subdomain' => $account->subdomain()] + $this->check->check($account->subdomain(), $account->username(), $account->password());
    }

    /**
     * After a shop's values became the values all shops share (Settings\LayoutChange): a Smaily
     * account per language is a shop's own setting, so the shared mode `a` becomes Single language
     * (ARCHITECTURE 5.1, Magento's promoteToStoreWide()).
     */
    public static function shareLanguageMode(StoreLayout $layout): void
    {
        if ($layout->languageMode(0) === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
            $layout->setLanguageMode(StoreLayout::MODE_SINGLE, 0);
        }
    }

    /**
     * Why the scope saves nothing: separate stores with no shop selected, or a multishop store that
     * has not said how its shops are run.
     *
     * @return list<array{field: string, message: string}>
     */
    public function scopeErrors(SetupScope $scope): array
    {
        if ($scope->targetShop() === null) {
            return [['field' => '', 'message' => $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.')]];
        }
        if ($scope->asksLayoutFirst()) {
            return [['field' => 'shape', 'message' => $this->trans('Pick how your shops are run.')]];
        }

        return [];
    }

    /**
     * The credentials of one block, checked: [errors, [subdomain, username, password] or null].
     * $field prefixes the fields' names ('' or 'accounts.<iso>.').
     *
     * @param array<string, mixed> $block
     *
     * @return array{0: list<array{field: string, message: string}>, 1: array{0: string, 1: string, 2: string}|null}
     */
    private function account(array $block, ?Account $stored, string $field, string $changedMessage, ?array $sharedPasswords = null): array
    {
        $subdomain = self::normalizeSubdomain((string) ($block['subdomain'] ?? ''));
        $username = trim((string) ($block['username'] ?? ''));
        $password = (string) ($block['password'] ?? '');

        $errors = [];
        foreach (['subdomain' => $subdomain, 'username' => $username] as $name => $value) {
            if ($value === '') {
                $errors[] = ['field' => $field . $name, 'message' => $this->trans('Subdomain and username are required.')];
            }
        }
        if ($errors !== []) {
            return [$errors, null];
        }
        if (preg_match(self::PLAIN_SUBDOMAIN, $subdomain) !== 1) {
            return [[['field' => $field . 'subdomain', 'message' => $this->trans('The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.')]], null];
        }
        if ($password === '') {
            if ($stored !== null && $stored->password() !== '' && self::sameAccount($stored, $subdomain, $username)) {
                $password = $stored->password();
            } elseif ($sharedPasswords !== null && isset($sharedPasswords[$subdomain . "\n" . $username])) {
                // Another language of this shop holds the same account: its password serves.
                $password = $sharedPasswords[$subdomain . "\n" . $username];
            } else {
                $message = $stored === null ? $this->trans('Please fill in the subdomain, username and password.') : $changedMessage;

                return [[['field' => $field . 'password', 'message' => $message]], null];
            }
        }

        return [[], [$subdomain, $username, $password]];
    }

    /**
     * Mode `a`: [errors, ISO code (and '' for the fallback) => credentials, fallback ISO code].
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: list<array{field: string, message: string}>, 1: array<string, array{0: string, 1: string, 2: string}>, 2: string}
     */
    private function perLanguageAccounts(SetupScope $scope, array $data, int $target): array
    {
        $posted = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
        $shared = [];
        foreach (array_merge([''], array_keys($scope->languages())) as $language) {
            $held = $this->accounts->find($target, $language);
            if ($held !== null && $held->password() !== '') {
                $shared[$held->subdomain() . "\n" . $held->username()] = $held->password();
            }
        }

        $errors = [];
        $accounts = [];
        foreach (array_keys($scope->languages()) as $iso) {
            $block = is_array($posted[$iso] ?? null) ? $posted[$iso] : [];
            $subdomain = trim((string) ($block['subdomain'] ?? ''));
            $username = trim((string) ($block['username'] ?? ''));
            if ($subdomain === '' && $username === '' && (string) ($block['password'] ?? '') === '') {
                continue;
            }
            $code = strtoupper($iso);
            if ($subdomain === '' || $username === '') {
                $errors[] = [
                    'field' => 'accounts.' . $iso . '.' . ($subdomain === '' ? 'subdomain' : 'username'),
                    'message' => $this->trans('Fill in the subdomain and username of the %language% account.', ['%language%' => $code]),
                ];
                continue;
            }
            [$blockErrors, $credentials] = $this->account(
                $block,
                $this->accounts->find($target, $iso),
                'accounts.' . $iso . '.',
                $this->trans('The subdomain or username of the %language% account changed — enter its password.', ['%language%' => $code]),
                $shared
            );
            $errors = array_merge($errors, $blockErrors);
            if ($credentials !== null) {
                $accounts[$iso] = $credentials;
            }
        }

        $fallback = (string) ($data['fallback_language'] ?? '');
        if ($errors === [] && !isset($accounts[$fallback])) {
            $errors[] = ['field' => 'fallback_language', 'message' => $this->trans('Pick the default fallback account.')];
        }
        if ($errors !== []) {
            return [$errors, [], ''];
        }
        $accounts[''] = $accounts[$fallback];

        return [[], $accounts, $fallback];
    }

    private static function sameAccount(Account $stored, string $subdomain, string $username): bool
    {
        return strcasecmp($stored->subdomain(), $subdomain) === 0 && $stored->username() === $username;
    }

    /**
     * @return array{errors: list<array{field: string, message: string}>, connected: bool, error: string}
     */
    private function testError(string $field, string $message): array
    {
        return ['errors' => [['field' => $field, 'message' => $message]], 'connected' => false, 'error' => $message];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, BaseController::DOMAIN);
    }
}
