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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A refusal that the same request meets again: an HTTP 3xx or 4xx other than 429, or a request or
 * an answer that the module itself does not accept. Nothing retries it.
 */
class EngineRequestException extends EngineException
{
    /** @var list<array{index: int|null, key: string, field: string}> */
    private array $fields;

    /** @var array<string, list<string>> */
    private array $details;

    /**
     * @param list<array{index: int|null, key: string, field: string}> $fields the fields that the
     *                                                                         engine names in its
     *                                                                         refusal (fields())
     * @param array<string, list<string>> $details the codes of the refusal's `details` (details())
     */
    public function __construct(string $errorCode, int $httpStatus = 0, array $fields = [], array $details = [])
    {
        parent::__construct($errorCode, $httpStatus);
        $this->fields = $fields;
        $this->details = $details;
    }

    /**
     * The codes that a refusal's `details` holds under $name (contract §19 `reasons` and
     * `sender_state`, §20 `blockers`): each a plain code (CODE_PATTERN), in the engine's order,
     * none twice. Never a text of the engine.
     *
     * @return list<string>
     */
    public function details(string $name): array
    {
        return $this->details[$name] ?? [];
    }

    /**
     * The fields at fault that a refusal names in its `errors[]` (contract §13): each entry's
     * position in the request (`index`, null when the engine names none), the row's natural key
     * (`key`, '' when the engine names none) and the field's path within the row. Never the
     * entry's message: it is the engine's text.
     *
     * @return list<array{index: int|null, key: string, field: string}>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
