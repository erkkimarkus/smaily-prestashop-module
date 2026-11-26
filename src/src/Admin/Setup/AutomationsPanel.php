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
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Automations panel (Initial setup step 3, Settings > Automations): the three store
 * automations' switches, their Smaily workflows and the abandoned-cart delay of the page's scope
 * (SetupScope), read and saved by this one class, as Magento's WizardStepSaver::saveAutomations()
 * and its MappingSaver save them.
 *
 * The workflows are rows of the mapping table (ARCHITECTURE 5.2), written at the scope's shop:
 * - every mode but `a`: each automation's own workflow, the row of no language and the account
 *   `default`. In mode `a` the page hides it and the save leaves it as it is: it was picked from
 *   no language's account.
 * - mode `b` (a workflow per language): one row per language under the account `default`;
 * - mode `a` (an account per language): one row per language under that language's account.
 *   In both, at most one language row of an automation is its default fallback. A save replaces
 *   only the rows of the shop's current mode; the other mode's rows stay for a switch back.
 *
 * Mode `b` draws one card per automation with a row per language and the default-fallback radio
 * on one of them, as WooCommerce and Shopify (owner, 2026-11-26; Linear CON-460): the page shows
 * no workflow of no language and no "None". That row stays stored for the modes `single` and `c`,
 * and the Router still reads it last in mode `b`, so a store that saved its mode-`b` rows before
 * keeps sending as before until it saves this page. The page then shows what the Router does
 * (fallbackLanguage()): the radio on the stored fallback; without one, on the first language
 * without its own workflow, whose row shows the stored workflow of no language, which keeps
 * serving every language without a workflow; else on the first language with a workflow. A
 * save posts that row unchanged, and it is stored as a copy of the row of no language (also
 * the 2.x opt-in trigger, and a workflow that the list no longer offers): what was sent, is
 * sent.
 *
 * A workflow is taken only from the live list of the account that sends it (WorkflowList), read
 * once per account and save. As in Magento (PRO-1286), a stored workflow stays where the page
 * posts it unchanged, and also where the page posts none while the list does not offer it (it
 * was deleted in Smaily, or the list could not be loaded): it was never shown as a choice, so the
 * empty value is no decision to clear it. The 2.x opt-in trigger of an upgraded store (decision 5)
 * is the value OPTIN: it stays while it is posted unchanged, and no save can newly pick it.
 *
 * An automation that is switched on without a workflow is saved, as in Magento: it sends nothing
 * (the Router skips it as `no_workflow`) until a workflow is picked. Nothing is written while any
 * field is at fault.
 *
 * `lost` names the automations whose workflow a change of the Smaily account or of the language
 * mode removed (Automation\LostWorkflows); the panel shows them until a save picks a workflow for
 * each.
 *
 * A shop that saves its own automations (Settings, one store) holds every row its page shows as
 * its own: an unchanged workflow of the shared rows is copied to the shop, and a workflow that the
 * shop clears is stored at the shop as "no workflow" (MappingRepository::setNone()) where a shared
 * row of that key exists, which the Router then no longer reads for the shop. A shop sends what
 * its page shows, as a Magento website with its own automations does.
 *
 * Each automation's discount code (Magento's "Send a discount code" block): its switch, the cart
 * rule that the codes are copies of and the days a code is valid. The picker's list comes from
 * the shop's cart rules now (Discount\TemplateRules). While the automation and the switch are on,
 * a save without a rule, or with days outside 1 to 365, is refused; with either off the fields
 * are hidden, so a value at fault is left unsaved and refuses nothing.
 */
final class AutomationsPanel
{
    /** The Welcome workflow's value for Smaily's generic opt-in trigger, which 2.x fired. */
    public const OPTIN = 'optin';

    private const PER_LANGUAGE_MODES = [StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, StoreLayout::MODE_WORKFLOW_PER_LANGUAGE];

    /** Why the workflow list could not be loaded, by the reason code of WorkflowList. */
    private const WORKFLOW_ERRORS = [
        WorkflowList::ERROR_NO_ACCOUNT => 'No Smaily account is saved for this shop and language.',
        WorkflowList::ERROR_NO_CREDENTIALS => 'The Smaily account has no subdomain, username or password.',
        WorkflowList::ERROR_INVALID_SUBDOMAIN => 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
        WorkflowList::ERROR_AUTHENTICATION => 'Smaily rejected these credentials. Check the subdomain, username and password.',
        WorkflowList::ERROR_PLAN_BLOCKED => 'Smaily refused the request because this account\'s package does not include API access. Upgrade the package in Smaily to connect — until then the credentials cannot be checked at all.',
        WorkflowList::ERROR_REFUSED => 'Smaily refused the request.',
        WorkflowList::ERROR_API => 'Smaily answered with an error.',
        WorkflowList::ERROR_UNAVAILABLE => 'Smaily did not answer. Try again in a moment.',
    ];

    private AutomationSettings $settings;

    private MappingRepository $mappings;

    private StoreLayout $layout;

    private Router $router;

    private WorkflowList $workflows;

    private TranslatorInterface $translator;

    /** @var callable(int): list<array{id: int, name: string}> */
    private $templateRules;

    /**
     * @param callable $templateRules `callable(int): list<array{id: int, name: string}>`: the
     *                                cart rules that a discount code can be a copy of in a shop,
     *                                or with 0 in every shop
     */
    public function __construct(
        AutomationSettings $settings,
        MappingRepository $mappings,
        StoreLayout $layout,
        Router $router,
        WorkflowList $workflows,
        TranslatorInterface $translator,
        callable $templateRules
    ) {
        $this->settings = $settings;
        $this->mappings = $mappings;
        $this->layout = $layout;
        $this->router = $router;
        $this->workflows = $workflows;
        $this->translator = $translator;
        $this->templateRules = $templateRules;
    }

    /**
     * The panel's values for the scope's target shop; null when the scope saves nothing. A
     * workflow is its id as a string, '' for none, or OPTIN. `languages` and `fallback` are filled
     * in the modes `a` and `b` only: each language's workflow, and the language whose row is the
     * automation's default fallback ('' for none). `discount` is the automation's discount code:
     * its switch, its cart rule (0 for none) and its days. `lost` lists the automations (and in
     * the modes `a` and `b` their language) that lost a workflow and have none picked again.
     * `account_fallback` is, in mode `a`, the language whose account takes a contact of a language
     * without a workflow (the Connection panel's fallback; '' otherwise), which the warning "Some
     * languages have no workflow" reads as the Router does. `cart_rule_url` opens PrestaShop's new
     * cart rule page ('' outside the back office).
     *
     * @return array{mode: string, cart_delay: int, triggers: array<string, array{enabled: bool, workflow: string, languages: array<string, string>, fallback: string, discount: array{enabled: bool, rule: int, days: int}}>, lost: list<array{trigger: string, language: string}>, account_fallback: string, cart_rule_url: string}|null
     */
    public function values(SetupScope $scope): ?array
    {
        $target = $scope->targetShop();
        if ($target === null) {
            return null;
        }
        $mode = $this->mode($scope, $target);
        $rows = $this->rows($target);
        $triggers = [];
        foreach (Trigger::ALL as $trigger) {
            $languages = [];
            $fallback = '';
            $workflow = self::value($rows[self::key($trigger, '', MappingRepository::ACCOUNT_DEFAULT)] ?? null);
            if (in_array($mode, self::PER_LANGUAGE_MODES, true)) {
                foreach (array_keys($scope->languages()) as $iso) {
                    $row = $rows[self::key($trigger, $iso, self::accountKey($mode, $iso))] ?? null;
                    $languages[$iso] = self::value($row);
                    if ($row !== null && $row['is_default_fallback']) {
                        $fallback = $iso;
                    }
                }
            }
            if ($mode === StoreLayout::MODE_WORKFLOW_PER_LANGUAGE && $fallback === '') {
                $fallback = self::fallbackLanguage($languages, $workflow);
                if ($fallback !== '' && $languages[$fallback] === '') {
                    $languages[$fallback] = $workflow;
                }
            }
            $triggers[$trigger] = [
                'enabled' => $this->settings->enabled($trigger, $target),
                'workflow' => $workflow,
                'languages' => $languages,
                'fallback' => $fallback,
                'discount' => [
                    'enabled' => $this->settings->discountEnabled($trigger, $target),
                    'rule' => $this->settings->discountRule($trigger, $target),
                    'days' => $this->settings->discountDays($trigger, $target),
                ],
            ];
        }

        $lost = array_values(array_filter(
            $this->settings->lostWorkflows($target),
            // Only what the page shows: a language's row in the modes `a` and `b`, else the card's own.
            static fn (array $entry): bool => $entry['language'] === ''
                ? !in_array($mode, self::PER_LANGUAGE_MODES, true) && $triggers[$entry['trigger']]['workflow'] === ''
                : ($triggers[$entry['trigger']]['languages'][$entry['language']] ?? null) === ''
        ));

        $link = \Context::getContext()->link;

        return [
            'mode' => $mode,
            'cart_delay' => $this->settings->cartDelayMinutes($target),
            'triggers' => $triggers,
            'lost' => $lost,
            'account_fallback' => $mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE ? $this->layout->fallbackLanguage($target) : '',
            // PrestaShop 8 answers the address HTML-escaped (`&amp;`); the template escapes it once.
            'cart_rule_url' => $link instanceof \Link ? str_replace('&amp;', '&', $link->getAdminLink('AdminCartRules', true, [], ['addcart_rule' => 1])) : '',
        ];
    }

    /**
     * The workflows that the selects of one account offer, asked of Smaily now: `default` is the
     * scope's account of no language, a language code that language's own account (mode `a`).
     * Where no such account is stored there is nothing to load, which is no failure: a language
     * of mode `a` without its own account has an empty list, as in Magento.
     *
     * @return array{workflows: list<array{id: int, name: string}>, error: ?string}
     */
    public function workflows(SetupScope $scope, string $accountKey): array
    {
        $target = $scope->targetShop();
        $list = $target !== null && preg_match('/^(default|[a-z]{2})$/', $accountKey) === 1
            ? $this->workflows->of($this->router->accountFor($target, $accountKey))
            : ['workflows' => [], 'error' => WorkflowList::ERROR_NO_ACCOUNT];

        $failed = $list['error'] !== null && $list['error'] !== WorkflowList::ERROR_NO_ACCOUNT;

        return ['workflows' => $list['workflows'], 'error' => $failed ? $this->listError($list['error']) : null];
    }

    /**
     * The cart rules that the discount pickers offer, read now: the ones a code can be a copy of
     * in the scope's shop, or for the values all shops share in every shop.
     *
     * @return array{rules: list<array{id: int, name: string}>}
     */
    public function discountRules(SetupScope $scope): array
    {
        $target = $scope->targetShop();

        return ['rules' => $target === null ? [] : ($this->templateRules)($target)];
    }

    /**
     * Checks and saves the posted `triggers` (each automation's `enabled`, `workflow`, in the
     * modes `a` and `b` `languages` (ISO code => workflow) and `fallback`, and `discount` with its
     * `enabled`, `rule` and `days`) and `cart_delay`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    public function save(SetupScope $scope, array $data): array
    {
        $target = $scope->targetShop();
        if ($target === null) {
            return [['field' => '', 'message' => $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.')]];
        }
        $mode = $this->mode($scope, $target);
        $cards = [];
        foreach (Trigger::ALL as $trigger) {
            $cards[$trigger] = is_array($data['triggers'][$trigger] ?? null) ? $data['triggers'][$trigger] : [];
        }

        $errors = [];
        $delay = $data['cart_delay'] ?? null;
        $delayValid = (is_int($delay) || (is_string($delay) && ctype_digit($delay)))
            && (int) $delay >= AutomationSettings::MIN_CART_DELAY_MINUTES
            && (int) $delay <= AutomationSettings::MAX_CART_DELAY_MINUTES;
        // A switched-off reminder hides the field: its value then blocks nothing and is not saved.
        if (!$delayValid && !empty($cards[Trigger::ABANDONED_CART]['enabled'])) {
            $errors[] = ['field' => 'cart_delay', 'message' => $this->trans(
                'The abandoned-cart wait must be from %min% to %max% minutes. A longer wait would send no reminder.',
                ['%min%' => AutomationSettings::MIN_CART_DELAY_MINUTES, '%max%' => AutomationSettings::MAX_CART_DELAY_MINUTES]
            )];
        }

        $discounts = [];
        foreach ($cards as $trigger => $card) {
            if (!is_array($card['discount'] ?? null)) {
                continue;
            }
            $on = !empty($card['enabled']) && !empty($card['discount']['enabled']);
            $rule = self::wholeNumber($card['discount']['rule'] ?? null);
            $days = self::wholeNumber($card['discount']['days'] ?? null);
            $daysValid = $days !== null && $days >= AutomationSettings::MIN_DISCOUNT_DAYS && $days <= AutomationSettings::MAX_DISCOUNT_DAYS;
            if ($on && ($rule === null || $rule <= 0)) {
                $errors[] = ['field' => $trigger . '.discount.rule', 'message' => $this->trans('Send a discount code is on, but no cart price rule is picked. Pick a rule, or switch the discount code off.')];
            }
            if ($on && !$daysValid) {
                $errors[] = ['field' => $trigger . '.discount.days', 'message' => $this->trans(
                    'A discount code must be valid for a whole number of days from %min% to %max%.',
                    ['%min%' => AutomationSettings::MIN_DISCOUNT_DAYS, '%max%' => AutomationSettings::MAX_DISCOUNT_DAYS]
                )];
            }
            // A hidden value at fault is not saved: the stored one stays.
            $discounts[$trigger] = [
                !empty($card['discount']['enabled']),
                $rule ?? $this->settings->discountRule($trigger, $target),
                $daysValid ? $days : $this->settings->discountDays($trigger, $target),
            ];
        }

        $stored = $this->rows($target);
        $lists = [];
        $writes = [];
        $languages = array_keys($scope->languages());
        foreach ($cards as $trigger => $card) {
            $selects = [];
            if ($mode !== StoreLayout::MODE_ACCOUNT_PER_LANGUAGE && array_key_exists('workflow', $card)) {
                $selects[] = ['', MappingRepository::ACCOUNT_DEFAULT, (string) $card['workflow'], false, $trigger . '.workflow'];
            }
            if (in_array($mode, self::PER_LANGUAGE_MODES, true) && is_array($card['languages'] ?? null)) {
                foreach ($languages as $iso) {
                    $selects[] = [$iso, self::accountKey($mode, $iso), (string) ($card['languages'][$iso] ?? ''), ($card['fallback'] ?? '') === $iso, $trigger . '.languages.' . $iso];
                }
            }
            $none = $stored[self::key($trigger, '', MappingRepository::ACCOUNT_DEFAULT)] ?? null;
            foreach ($selects as [$language, $accountKey, $value, $fallback, $field]) {
                $row = $stored[self::key($trigger, $language, $accountKey)] ?? null;
                if ($value === self::value($row)) {
                    // Unchanged: the scope's shop holds the row from now on, with the posted fallback.
                    if ($row !== null) {
                        $writes[] = [$trigger, $language, $accountKey, $row['workflow_id'], $row['optin_trigger'], $fallback];
                    }
                    continue;
                }
                if ($mode === StoreLayout::MODE_WORKFLOW_PER_LANGUAGE && $language !== '' && $row === null && $value !== '' && $value === self::value($none)) {
                    // Mode `b` shows the workflow of no language on the row that it serves (values()).
                    $writes[] = [$trigger, $language, $accountKey, $none['workflow_id'], $none['optin_trigger'], $fallback];
                    continue;
                }
                $lists[$accountKey] ??= $this->workflows->of($this->router->accountFor($target, $accountKey));
                $offered = array_column($lists[$accountKey]['workflows'], 'id');
                if ($value === '') {
                    if ($row['optin_trigger'] || in_array($row['workflow_id'], $offered, true)) {
                        $writes[] = [$trigger, $language, $accountKey, null, false, false];
                    }
                } elseif ($lists[$accountKey]['error'] !== null) {
                    $errors[] = ['field' => $field, 'message' => $this->listError($lists[$accountKey]['error'])];
                } elseif (!ctype_digit($value) || !in_array((int) $value, $offered, true)) {
                    $errors[] = ['field' => $field, 'message' => $this->trans('This workflow is not in the list of your Smaily account. Press Refresh workflows and pick a workflow from the list.')];
                } else {
                    $writes[] = [$trigger, $language, $accountKey, (int) $value, false, $fallback];
                }
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        foreach ($cards as $trigger => $card) {
            $this->settings->setEnabled($trigger, !empty($card['enabled']), $target);
        }
        if ($delayValid) {
            $this->settings->setCartDelayMinutes((int) $delay, $target);
        }
        foreach ($discounts as $trigger => [$enabled, $rule, $days]) {
            $this->settings->setDiscount($trigger, $enabled, $rule, $days, $target);
        }
        $shared = $this->sharedRows($target);
        foreach ($writes as [$trigger, $language, $accountKey, $workflowId, $optin, $fallback]) {
            if ($workflowId === null && !$optin) {
                if (isset($shared[self::key($trigger, $language, $accountKey)])) {
                    // The shop reads a shared row of this key: its own row says "no workflow".
                    $this->mappings->setNone($target, $trigger, $language, $accountKey);
                } else {
                    $this->mappings->delete($target, $trigger, $language, $accountKey);
                }
            } else {
                $this->mappings->upsert($target, $trigger, $language, $accountKey, $workflowId, $optin, $fallback);
            }
        }
        if (in_array($mode, self::PER_LANGUAGE_MODES, true)) {
            $this->removeRowsOfOtherLanguages($target, $mode, $languages);
        }
        // A lost workflow that is picked again leaves the panel's list.
        $this->settings->setLostWorkflows($target, $this->values($scope)['lost'] ?? []);

        return [];
    }

    /**
     * The language mode the panel shows and saves: the shop's stored mode where the scope offers it
     * (ConnectionPanel's rule), else Single language.
     */
    private function mode(SetupScope $scope, int $target): string
    {
        $mode = $this->layout->languageMode($target);

        return in_array($mode, $scope->languageModes(), true) ? $mode : StoreLayout::MODE_SINGLE;
    }

    /**
     * The mapping rows the shop reads, as the Router reads them: its own row before a shared one,
     * keyed by trigger, language and account key.
     *
     * @return array<string, array{id: int, id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: bool, is_default_fallback: bool}>
     */
    private function rows(int $target): array
    {
        $rows = [];
        foreach ($this->router->rowShops($target) as $shop) {
            foreach ($this->mappings->rows($shop) as $row) {
                $rows[self::key($row['trigger'], $row['language'], $row['account_key'])] ??= $row;
            }
        }

        return $rows;
    }

    /**
     * The rows all shops share that a shop reads beside its own (the Router's rule), keyed as
     * rows(); none for the shared scope itself, and for a shop that reads only its own rows.
     *
     * @return array<string, array{id: int, id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: bool, is_default_fallback: bool}>
     */
    private function sharedRows(int $target): array
    {
        $rows = [];
        if ($target > 0 && in_array(0, $this->router->rowShops($target), true)) {
            foreach ($this->mappings->rows(0) as $row) {
                $rows[self::key($row['trigger'], $row['language'], $row['account_key'])] = $row;
            }
        }

        return $rows;
    }

    /**
     * A per-language mode's rows of a language that the scope no longer has are not on the page:
     * they go, so that none of them stays an automation's default fallback (Magento's MappingSaver
     * deletes the rows that the posted state no longer contains).
     *
     * @param list<string> $languages
     */
    private function removeRowsOfOtherLanguages(int $target, string $mode, array $languages): void
    {
        foreach ($this->mappings->rows($target) as $row) {
            $ofMode = ($row['account_key'] === MappingRepository::ACCOUNT_DEFAULT) === ($mode === StoreLayout::MODE_WORKFLOW_PER_LANGUAGE);
            if ($ofMode && $row['language'] !== '' && !in_array($row['language'], $languages, true)) {
                $this->mappings->delete($target, $row['trigger'], $row['language'], $row['account_key']);
            }
        }
    }

    /**
     * Mode `b`'s default fallback where none is stored: the language that the stored workflow of
     * no language serves as the Router reads it, the first one without its own workflow, else the
     * first language with a workflow (WooCommerce's first mapped row), else the first language.
     *
     * @param array<string, string> $languages each language's own workflow ('' for none)
     */
    private static function fallbackLanguage(array $languages, string $workflow): string
    {
        if ($workflow !== '') {
            foreach ($languages as $iso => $own) {
                if ($own === '') {
                    return (string) $iso;
                }
            }
        }
        foreach ($languages as $iso => $own) {
            if ($own !== '') {
                return (string) $iso;
            }
        }

        return (string) (array_key_first($languages) ?? '');
    }

    private static function accountKey(string $mode, string $language): string
    {
        return $mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE ? $language : MappingRepository::ACCOUNT_DEFAULT;
    }

    private static function key(string $trigger, string $language, string $accountKey): string
    {
        return $trigger . '|' . $language . '|' . $accountKey;
    }

    /**
     * @param array{workflow_id: int|null, optin_trigger: bool}|null $row
     */
    private static function value(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return $row['workflow_id'] === null ? ($row['optin_trigger'] ? self::OPTIN : '') : (string) $row['workflow_id'];
    }

    /**
     * A posted whole number that is 0 or more, or null for anything else.
     *
     * @param mixed $value
     */
    private static function wholeNumber($value): ?int
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * Magento's sentence with the reason of WorkflowList in words; it never carries Smaily's text.
     */
    public function listError(string $reason): string
    {
        return $this->trans('Could not load the workflow list: %error%', [
            '%error%' => isset(self::WORKFLOW_ERRORS[$reason]) ? $this->trans(self::WORKFLOW_ERRORS[$reason]) : $reason,
        ]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, BaseController::DOMAIN);
    }
}
