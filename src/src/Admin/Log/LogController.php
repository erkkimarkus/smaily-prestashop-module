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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogStatus;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Log page: one list of everything sent, failed or skipped, over both queues and the import
 * jobs (Log\LogRows), newest first, with filters and pages. The shell renders its frame
 * (layout/log.html.twig); this controller hands the list to views/templates/admin/log/ as
 * `smaily_log`. The page reads only; Details, "Send again" and "Retry" are the JSON endpoints of
 * this folder.
 *
 * The shops are those of PrestaShop's shop selector: one shop, the shops of a group, or all.
 */
final class LogController extends BaseController
{
    public const PER_PAGE = 20;

    private const TEMPLATE = '@Modules/smailyforprestashop/views/templates/admin/log/index.html.twig';

    private const DAY = 86400;

    public function index(Request $request): Response
    {
        $tab = self::PAGES['log']['tab'];
        if (!$this->isGranted('read', $tab) || self::isSetupForced()) {
            // The shell refuses the page, or sends it to the Initial setup.
            return $this->renderPage('log');
        }

        $filter = new LogFilter($request->query->all());
        $rows = self::rows();
        $view = new LogView(self::translate());
        $total = $rows->count($filter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));
        $list = $rows->page($filter, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $failed = [LogRows::SMAILY => [], LogRows::INTELLIGENCE => []];
        foreach ($list as $row) {
            if (isset($failed[$row['kind']]) && $row['status'] === EventQueue::STATUS_FAILED) {
                $failed[$row['kind']][] = (int) $row['id'];
            }
        }
        $guard = new ResendGuard();
        $refused = [
            LogRows::SMAILY => $guard->refusals($rows->eventRows($failed[LogRows::SMAILY])),
            LogRows::INTELLIGENCE => $guard->ingestRefusals($rows->ingestRows($failed[LogRows::INTELLIGENCE])),
        ];

        return $this->renderPage('log', ['smaily_log' => [
            'failed' => $rows->failedSince(time() - self::DAY),
            'filter' => $filter->values(),
            // PrestaShop's token of the page's address, which the filter form (a GET form) sends along.
            'token' => (string) $request->query->get('_token', ''),
            'sources' => [
                ['value' => LogRows::SMAILY, 'label' => $view->sourceLabel(LogRows::SMAILY)],
                ['value' => LogRows::INTELLIGENCE, 'label' => $view->sourceLabel(LogRows::INTELLIGENCE)],
            ],
            'statuses' => array_map(
                static fn (string $status): array => ['value' => $status, 'label' => $view->statusLabel($status)],
                array_merge(LogStatus::QUEUE, LogStatus::JOB_ONLY)
            ),
            'rows' => array_map(
                static fn (array $row): array => $view->row($row, in_array((int) $row['id'], $failed[$row['kind']] ?? [], true) && !isset($refused[$row['kind']][(int) $row['id']])),
                $list
            ),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'from' => $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
            'to' => ($page - 1) * self::PER_PAGE + count($list),
            'can_update' => $this->isGranted('update', $tab),
        ]], self::TEMPLATE);
    }

    /**
     * The Log's reads for the shops of PrestaShop's shop selector.
     */
    public static function rows(): LogRows
    {
        $shops = \Shop::isFeatureActive() && \Shop::getContext() !== \Shop::CONTEXT_ALL
            ? array_map('intval', \Shop::getContextListShopID())
            : null;
        $translate = self::translate();

        return new LogRows($shops, static fn (string $term): array => FailureMessage::patternsShowing($term, $translate));
    }

    /**
     * The admin's translation of an EN string of the Log.
     *
     * @return callable(string, array<string, string|int>=): string
     */
    public static function translate(): callable
    {
        $translator = self::translator();

        return static fn (string $id, array $parameters = []): string => $translator->trans($id, $parameters, self::DOMAIN);
    }
}
