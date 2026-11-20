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
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the shops of a multishop store are run (Magento's store-shape panel, CON-279): one store in
 * several versions, or separate stores. The Initial setup asks it first while no layout is
 * stored; the answer is stored where Scope\StoreLayout reads it.
 *
 * Moving a store that has saved settings from one layout to the other needs its values copied or
 * removed shop by shop (Magento's toSeparateStores() and toOneStore()); that change belongs to
 * Settings, so this panel only stores a first answer, or the layout the store already has.
 */
final class StoreShapePanel
{
    public const ONE_STORE = 'one';

    public const SEPARATE_STORES = 'separate';

    private StoreLayout $layout;

    private TranslatorInterface $translator;

    public function __construct(StoreLayout $layout, TranslatorInterface $translator)
    {
        $this->layout = $layout;
        $this->translator = $translator;
    }

    /**
     * @return array{shape: string, first: bool, shops: list<string>}|null null on a store without several shops
     */
    public function values(SetupScope $scope): ?array
    {
        if (!$scope->multishop()) {
            return null;
        }

        return [
            'shape' => $scope->separateStores() ? self::SEPARATE_STORES : self::ONE_STORE,
            'first' => $scope->asksLayoutFirst(),
            'shops' => $scope->shopNames(),
        ];
    }

    /**
     * Why $shape is not an answer: the store has one shop, or the shape is none of the two.
     *
     * @return list<array{field: string, message: string}>
     */
    public function shapeErrors(SetupScope $scope, string $shape): array
    {
        if (!$scope->multishop() || !in_array($shape, [self::ONE_STORE, self::SEPARATE_STORES], true)) {
            return [['field' => 'shape', 'message' => $this->trans('Pick how your shops are run.')]];
        }

        return [];
    }

    /**
     * Stores the posted `shape`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    public function save(SetupScope $scope, array $data): array
    {
        $shape = (string) ($data['shape'] ?? '');
        $errors = $this->shapeErrors($scope, $shape);
        if ($errors !== []) {
            return $errors;
        }
        $separate = $shape === self::SEPARATE_STORES;
        if (!$scope->asksLayoutFirst()) {
            return $separate === $scope->separateStores()
                ? []
                : [['field' => 'shape', 'message' => $this->trans('How the shops are run is changed under Settings.')]];
        }
        $this->layout->setSeparateStores($separate);

        return [];
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], BaseController::DOMAIN);
    }
}
