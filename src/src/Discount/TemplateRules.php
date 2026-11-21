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

namespace PrestaShop\Module\SmailyForPrestaShop\Discount;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The merchant's cart rules that an automation's discount codes can be copies of (the templates;
 * the Magento connector's Discount\RuleSource and the WooCommerce connector's template coupon):
 *  - a cart rule that the merchant made, never one this module issued (CodeIssuer::MARKER);
 *  - not limited to one customer (`id_customer`): a code is a copy for another shopper, and the
 *    vouchers that PrestaShop makes itself are a customer's own (the voucher of a refund, the
 *    remainder of a voucher that was spent in part, a discount or free shipping that an employee
 *    gives an order);
 *  - not past its end date, in the shop's time;
 *  - usable in the shop: without a shop restriction, or with the shop among its shops.
 *
 * A template may be disabled, and should be unless it has a code of its own: PrestaShop applies an
 * enabled cart rule without a code to every cart by itself, and anyone who knows the code of an
 * enabled one can use it. A copy is always issued enabled. Everything else of the rule (the
 * discount, its conditions, its restrictions) stays in the rule and is not read here.
 */
final class TemplateRules
{
    /** The most rules the picker lists. */
    public const LIST_LIMIT = 200;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock the current Unix time; time() when null
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * The rules that can be a template in every one of $shops, by name: the picker's list.
     *
     * @param list<int> $shops
     * @param int $idLang the language of the names
     *
     * @return list<array{id: int, name: string}> a rule without a name in that language shows its code
     */
    public function listFor(array $shops, int $idLang): array
    {
        $shops = array_values(array_unique(array_map('intval', $shops)));
        $rows = \Db::getInstance()->executeS(sprintf(
            'SELECT cr.`id_cart_rule`, cr.`code`, crl.`name` FROM `%1$scart_rule` cr'
            . ' LEFT JOIN `%1$scart_rule_lang` crl ON crl.`id_cart_rule` = cr.`id_cart_rule` AND crl.`id_lang` = %2$d'
            . ' WHERE %3$s AND cr.`id_customer` = 0 AND (cr.`date_to` IS NULL OR cr.`date_to` = \'0000-00-00 00:00:00\' OR cr.`date_to` >= \'%4$s\')'
            . ' AND (cr.`shop_restriction` = 0 OR (SELECT COUNT(*) FROM `%1$scart_rule_shop` crs'
            . ' WHERE crs.`id_cart_rule` = cr.`id_cart_rule` AND crs.`id_shop` IN (%5$s)) = %6$d)'
            . ' ORDER BY crl.`name`, cr.`id_cart_rule` LIMIT %7$d',
            _DB_PREFIX_,
            $idLang,
            CodeIssuer::notIssued('cr'),
            pSQL(date('Y-m-d H:i:s', ($this->clock)())),
            $shops === [] ? '0' : implode(', ', $shops),
            count($shops),
            self::LIST_LIMIT
        ), true, false);

        $rules = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = trim((string) $row['name']);
            $rules[] = ['id' => (int) $row['id_cart_rule'], 'name' => $name !== '' ? $name : (string) $row['code']];
        }

        return $rules;
    }

    /**
     * The rule, with its names in every language, when a code can be a copy of it for the shop
     * now; null when it is gone, was issued by this module, is limited to one customer, is past
     * its end date or is not usable in the shop.
     */
    public function usable(int $idRule, int $idShop): ?\CartRule
    {
        $rule = new \CartRule($idRule);
        if (!\Validate::isLoadedObject($rule) || CodeIssuer::isIssued($rule) || (int) $rule->id_customer > 0) {
            return null;
        }
        $end = self::end($rule);
        if ($end !== null && $end < ($this->clock)()) {
            return null;
        }
        if ((int) $rule->shop_restriction === 1 && !\Db::getInstance()->getValue(sprintf(
            'SELECT 1 FROM `%scart_rule_shop` WHERE `id_cart_rule` = %d AND `id_shop` = %d',
            _DB_PREFIX_,
            $idRule,
            $idShop
        ), false)) {
            return null;
        }

        return $rule;
    }

    /**
     * The Unix time of the rule's end date, read in the shop's time; null for a rule without one.
     */
    public static function end(\CartRule $rule): ?int
    {
        $end = (string) $rule->date_to;
        if ($end === '' || strpos($end, '0000-00-00') === 0) {
            return null;
        }
        $time = strtotime($end);

        return $time === false ? null : $time;
    }
}
