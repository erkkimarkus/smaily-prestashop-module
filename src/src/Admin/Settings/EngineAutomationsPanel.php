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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Automations\EngineAutomations;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The card "Campaign Intelligence Automations" of Settings > Automations (Magento's
 * config/engine-automations.phtml): what the card shows for a shop, and its own save, in the
 * admin's words. Engine\Automations\EngineAutomations reads and saves; this class words the
 * outcome.
 *
 * The names and the descriptions of the automations are the engine's own text (contract §11), the
 * one text of the engine that a screen shows. Everything else is the module's: why the list could
 * not be loaded, why a save did not go through and what is wrong with a field are sentences of
 * this class, chosen by the HTTP status, the error code and the field's name.
 */
final class EngineAutomationsPanel
{
    /** The control that a field of the engine's refusal marks, and the sentence under it. */
    private const REFUSED_FIELDS = [
        'automation_map' => ['workflow_id', 'Campaign Intelligence did not accept this workflow. Pick another workflow, then save again.'],
        'test_emails' => ['test_emails', 'Campaign Intelligence did not accept these test emails. Enter email addresses separated by commas, at most 50.'],
    ];

    /** What marks the row's Enabled when the engine refuses any other field of the row. */
    private const REFUSED_ROW = ['enabled', 'Campaign Intelligence did not accept this automation. Reload the page and try again.'];

    private EngineAutomations $automations;

    private AccountResolver $accounts;

    /** @var callable(string): string */
    private $workflowListError;

    private TranslatorInterface $translator;

    /**
     * @param callable $workflowListError `callable(string): string`: the sentence of a reason code
     *                                    of Automation\WorkflowList
     *                                    (Admin\Setup\AutomationsPanel::listError())
     */
    public function __construct(EngineAutomations $automations, AccountResolver $accounts, callable $workflowListError, TranslatorInterface $translator)
    {
        $this->automations = $automations;
        $this->accounts = $accounts;
        $this->workflowListError = $workflowListError;
        $this->translator = $translator;
    }

    /**
     * What the card shows (views/templates/admin/settings/engine-automations-card.html.twig).
     *
     * @param int $idShop the shop the page saves for: 0 in one store, the selected shop in
     *                    separate stores
     * @param string $language the employee's language, an ISO 639-1 code
     *
     * @return array{state: string, reason: string, rows: list<array<string, mixed>>, workflows: list<array{id: int, name: string}>, workflow_error: string, account: string}
     */
    public function card(int $idShop, string $language): array
    {
        return $this->worded($this->automations->view($idShop, $language), $idShop);
    }

    /**
     * Saves the card's rows. After a save that went through, `card` is what the card shows now
     * (null when Campaign Intelligence could not be read again). Else `code` says why nothing was
     * saved (`invalid_fields`; `card_changed`: the account is refused or the connection is gone,
     * and the card says so once it is asked again; or `not_saved`) and `fields` names each field at
     * fault as `<catalogue key>.<control>`.
     *
     * @param array<mixed> $posted the rows by catalogue key
     *
     * @return array{saved: bool, code: string, message: string, card: array<string, mixed>|null, fields: list<array{field: string, message: string}>}
     */
    public function save(int $idShop, array $posted, string $language): array
    {
        $result = $this->automations->save($idShop, $posted, $language);
        switch ($result['outcome']) {
            case EngineAutomations::SAVED:
                $ready = $result['view']['state'] === EngineAutomations::READY;
                $message = $this->trans('Saved.');
                if (!$ready) {
                    $message = $this->trans('Saved. Reload the page to see the state of each trigger.');
                } elseif ($result['kept_in_test']) {
                    $message .= ' ' . $this->trans('Smaily switches real sends on after you confirm. Until then the trigger runs in test mode.');
                }

                return ['saved' => true, 'code' => '', 'message' => $message, 'card' => $ready ? $this->worded($result['view'], $idShop) : null, 'fields' => []];
            case EngineAutomations::INVALID:
                return $this->invalid($result['faults']);
            case EngineAutomations::FAILED:
                return self::notSaved($this->trans('Nothing was saved: %reason%', ['%reason%' => $this->reason($result['failure'])]));
            case EngineAutomations::NOTHING:
                return self::notSaved($this->trans('Saving failed.'));
            default:
                // The account is refused, or the connection is gone: the card says which.
                return self::notSaved($this->trans('Saving failed.'), 'card_changed');
        }
    }

    /**
     * @param array<string, mixed> $view what EngineAutomations::view() answered
     *
     * @return array{state: string, reason: string, rows: list<array<string, mixed>>, workflows: list<array{id: int, name: string}>, workflow_error: string, account: string}
     */
    private function worded(array $view, int $idShop): array
    {
        $workflowError = $view['workflow_error'] ?? null;
        // Where the connection belongs to another Smaily account than the shop's, the card says
        // whose workflows it lists (the shop's account: of no language, in mode `a` of the
        // fallback language, AccountResolver).
        $account = (string) ($view['account'] ?? '');
        $own = $this->accounts->for($idShop, '');
        $other = $account !== '' && ($own === null || strcasecmp($own->subdomain(), $account) !== 0);

        return [
            'state' => $view['state'],
            'reason' => isset($view['failure']) ? $this->reason($view['failure']) : '',
            'rows' => $view['rows'] ?? [],
            'workflows' => $view['workflows'] ?? [],
            'workflow_error' => $workflowError === null ? '' : ($this->workflowListError)($workflowError),
            'account' => $other ? $account : '',
        ];
    }

    /**
     * @param list<array{key: string, field: string, fault: string}> $faults
     *
     * @return array{saved: bool, code: string, message: string, card: null, fields: list<array{field: string, message: string}>}
     */
    private function invalid(array $faults): array
    {
        $fields = [];
        $withoutWorkflow = 0;
        foreach ($faults as $fault) {
            if ($fault['fault'] === EngineAutomations::FAULT_WORKFLOW_REQUIRED) {
                ++$withoutWorkflow;
                $fields[] = ['field' => $fault['key'] . '.workflow_id', 'message' => $this->trans('A workflow is required when this trigger is enabled.')];
                continue;
            }
            [$control, $sentence] = self::REFUSED_FIELDS[$fault['field']] ?? self::REFUSED_ROW;
            $fields[] = ['field' => $fault['key'] . '.' . $control, 'message' => $this->trans($sentence)];
        }
        $message = $withoutWorkflow > 0
            ? $this->trans('%count% automation(s) could not be saved. Pick a Smaily workflow for every enabled trigger, then save again.', ['%count%' => $withoutWorkflow])
            : $this->trans('Campaign Intelligence did not accept the marked fields. Nothing was saved.');

        return ['saved' => false, 'code' => 'invalid_fields', 'message' => $message, 'card' => null, 'fields' => $fields];
    }

    /**
     * @return array{saved: bool, code: string, message: string, card: null, fields: array{}}
     */
    private static function notSaved(string $message, string $code = 'not_saved'): array
    {
        return ['saved' => false, 'code' => $code, 'message' => $message, 'card' => null, 'fields' => []];
    }

    /**
     * Why a call of Campaign Intelligence failed, in the module's words: the failure's kind and
     * its error code, never the engine's message.
     */
    private function reason(EngineException $failure): string
    {
        return $failure instanceof EngineTransportException
            ? $this->trans('Campaign Intelligence did not answer.')
            : $this->trans('Campaign Intelligence refused the request (%code%).', ['%code%' => $failure->errorCode()]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, BaseController::DOMAIN);
    }
}
