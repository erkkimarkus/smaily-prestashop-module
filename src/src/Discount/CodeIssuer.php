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
 * Issues one shopper's discount code as a copy of a template cart rule, the way PrestaShop itself
 * copies a rule (the remainder voucher of PaymentModule): the template's main row, then
 * CartRule::copyConditions() for its shop, carrier, group, country, combination and product
 * restrictions. The discount and every condition are the template's. The copy differs in this:
 *  - a new code, unique among the cart rules: CODE_LENGTH letters and digits of ALPHABET;
 *  - the name "Smaily Connect – <the template's name>" in every language, under which Catalog >
 *    Discounts lists it;
 *  - the description is the marker (MARKER, the rule's own code, the template's id and the
 *    automation), by which the module knows its own rules: no column or table holds that;
 *  - it can be used once (quantity 1, once per customer), is never advertised in a cart
 *    (highlight off) and is enabled, also where the template is disabled;
 *  - it is valid from now (its start date is a day back, see issue()) until 23:59:59 of the day
 *    $days days from today, in the shop's time; never before the template's own start date and
 *    never after its end date. A template that starts only after the code would end gives none;
 *  - it is tied to a customer account when the shopper has one (`id_customer`); else anyone who
 *    enters it can use it, once.
 *
 * PrestaShop enforces all of it at the cart (CartRule::checkValidity()): the module adds no check
 * of its own.
 *
 * Two questions are asked of a cart rule's description, and they differ on purpose:
 *  - isIssued(), issued(): the rule is a code of this module. Its description names its own code
 *    (`smaily-connect-issued: code=<its code> …`). Only such a rule is ever removed (IssuedCodes).
 *    The remainder voucher that PrestaShop makes when a code is spent in part copies the
 *    description and gets a code of its own, so it is not one: it holds a shopper's credit. A
 *    rule of a development build before 3.0.0, whose marker names no code, is not one either.
 *  - isMarked(), notMarked(): the description starts with MARKER at all. Such a rule comes from a
 *    code and is never a template (TemplateRules), the remainder voucher included.
 */
final class CodeIssuer
{
    /** What the description of every cart rule this module issued starts with. */
    public const MARKER = 'smaily-connect-issued:';

    /** What follows MARKER in the description of an issued rule, before the rule's own code. */
    private const MARKER_CODE = ' code=';

    /** What the name of every issued rule starts with, before the template's name. */
    public const NAME_PREFIX = 'Smaily Connect – ';

    public const CODE_LENGTH = 10;

    /** Upper-case letters and digits without the look-alikes 0, O, 1 and I. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** The length of a name that PrestaShop accepts. */
    private const NAME_LENGTH = 254;

    private const CODE_ATTEMPTS = 5;

    private const START_MARGIN_SECONDS = 86400;

    private TemplateRules $templates;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock the current Unix time; time() when null
     */
    public function __construct(TemplateRules $templates, ?callable $clock = null)
    {
        $this->templates = $templates;
        $this->clock = $clock ?? 'time';
    }

    /**
     * A new code as a copy of the rule, its cart rule's id and its last valid day (`Y-m-d`, the
     * shop's time); null
     * when no code can be a copy of the rule for the shop now (TemplateRules::usable()), or when
     * the rule starts only after the code would end.
     *
     * @param int $idCustomer the shopper's customer account, 0 for a shopper without one
     * @param string $trigger the automation that sends the code (Automation\Trigger)
     *
     * @return array{id: int, code: string, expires_at: string}|null
     *
     * @throws \Throwable when the rule could not be read, or PrestaShop does not store the copy;
     *                    no rule is left behind
     */
    public function issue(int $idRule, int $days, int $idShop, int $idCustomer, string $trigger): ?array
    {
        $rule = $this->templates->usable($idRule, $idShop);
        if ($rule === null) {
            return null;
        }
        $now = ($this->clock)();
        $end = (int) strtotime(date('Y-m-d 23:59:59', (int) strtotime(sprintf('+%d days', $days), $now)));
        $templateEnd = TemplateRules::end($rule);
        if ($templateEnd !== null && $templateEnd < $end) {
            $end = $templateEnd;
        }
        // PrestaShop 8 lists a customer's vouchers by the database server's clock, which can be hours
        // behind the shop's: a start a day back shows the code at once, and nobody held it before now.
        // A template that starts later is not valid before its start, and neither is its code.
        $start = max($now - self::START_MARGIN_SECONDS, (int) TemplateRules::start($rule));
        if ($start >= $end) {
            return null;
        }

        // PrestaShop adds an object without an id as a new row.
        $rule->id = null;
        $rule->code = $this->newCode();
        foreach ((array) $rule->name as $idLang => $name) {
            $rule->name[$idLang] = mb_substr(self::NAME_PREFIX . $name, 0, self::NAME_LENGTH);
        }
        $rule->description = sprintf('%s%s%s template=%d trigger=%s', self::MARKER, self::MARKER_CODE, $rule->code, $idRule, $trigger);
        $rule->id_customer = $idCustomer;
        $rule->quantity = 1;
        if (property_exists($rule, 'total_quantity')) {
            // PrestaShop 9: the quantity the rule started with.
            $rule->total_quantity = 1;
        }
        $rule->quantity_per_user = 1;
        $rule->highlight = false;
        $rule->active = true;
        $rule->date_from = date('Y-m-d H:i:s', $start);
        $rule->date_to = date('Y-m-d H:i:s', $end);
        try {
            if (!$rule->add()) {
                throw new \RuntimeException('PrestaShop did not store the discount code.');
            }
            \CartRule::copyConditions($idRule, (int) $rule->id);
        } catch (\Throwable $error) {
            // PrestaShop stores the main row first: its names, or another module's hook on a new
            // cart rule, can still fail. No half-made rule stays, so a retry starts anew.
            if ((int) $rule->id > 0) {
                try {
                    $rule->delete();
                } catch (\Throwable $ignored) {
                    \Db::getInstance()->delete('cart_rule', '`id_cart_rule` = ' . (int) $rule->id);
                    \Db::getInstance()->delete('cart_rule_lang', '`id_cart_rule` = ' . (int) $rule->id);
                }
            }
            throw $error;
        }

        // An end at 00:00:00 (a template's) leaves the day before as the last valid day.
        return ['id' => (int) $rule->id, 'code' => (string) $rule->code, 'expires_at' => date('Y-m-d', $end - 1)];
    }

    /**
     * Removes a code that issue() just made and that nobody was sent. A cart rule that is not a
     * code of this module is left alone.
     */
    public function discard(int $idRule): void
    {
        $rule = new \CartRule($idRule);
        if (\Validate::isLoadedObject($rule) && self::isIssued($rule)) {
            $rule->delete();
        }
    }

    /**
     * Whether the cart rule is a code this module issued: its description names its own code.
     */
    public static function isIssued(\CartRule $rule): bool
    {
        $prefix = self::MARKER . self::MARKER_CODE;
        $description = (string) $rule->description;
        $code = (string) $rule->code;

        return $code !== '' && strpos($description, $prefix) === 0 && explode(' ', substr($description, strlen($prefix)), 2)[0] === $code;
    }

    /**
     * The SQL condition that the cart rule read as $alias is a code this module issued, as
     * isIssued() decides it: the codes are compared byte by byte.
     */
    public static function issued(string $alias): string
    {
        $prefix = self::MARKER . self::MARKER_CODE;

        return sprintf(
            '(%1$s.`description` LIKE \'%2$s%%\' AND %1$s.`code` <> \'\''
            . ' AND CAST(SUBSTRING_INDEX(SUBSTRING(%1$s.`description`, %3$d), \' \', 1) AS BINARY) = CAST(%1$s.`code` AS BINARY))',
            $alias,
            pSQL($prefix),
            strlen($prefix) + 1
        );
    }

    /**
     * Whether the cart rule's description starts with the marker: a code this module issued, or a
     * rule that comes from one.
     */
    public static function isMarked(\CartRule $rule): bool
    {
        return strpos((string) $rule->description, self::MARKER) === 0;
    }

    /**
     * The SQL condition that the cart rule read as $alias is not one that isMarked() means.
     */
    public static function notMarked(string $alias): string
    {
        return sprintf('(%1$s.`description` IS NULL OR %1$s.`description` NOT LIKE \'%2$s%%\')', $alias, pSQL(self::MARKER));
    }

    private function newCode(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; ++$attempt) {
            $code = '';
            for ($index = 0; $index < self::CODE_LENGTH; ++$index) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (!\CartRule::getIdByCode($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('No unused discount code was found.');
    }
}
