<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * Turns the test shop into a multistore shop for one test: switches multishop on, adds shops and
 * shop groups, and puts everything back afterwards. The added rows carry only what Shop and
 * Configuration read; nothing else of a shop (catalog, languages, URLs beyond the main one) exists.
 *
 * Call openMultistore() in setUp() and closeMultistore() in tearDown().
 */
trait MultistoreShop
{
    /** @var list<int> */
    private array $addedShops = [];

    /** @var list<int> */
    private array $addedShopGroups = [];

    /** @var array<int, int> id_shop_group => share_customer before the test */
    private array $savedShareCustomer = [];

    /** @var string|false */
    private $savedMultishop = false;

    private int $savedContext = 0;

    private ?int $savedContextId = null;

    private function openMultistore(): void
    {
        $this->savedMultishop = \Configuration::getGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE');
        $this->savedContext = (int) \Shop::getContext();
        $this->savedContextId = $this->savedContext === \Shop::CONTEXT_GROUP
            ? (int) \Shop::getContextShopGroupID()
            : ($this->savedContext === \Shop::CONTEXT_SHOP ? (int) \Shop::getContextShopID() : null);
        \Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', '1');
        $this->resetShopCaches();
    }

    private function closeMultistore(): void
    {
        $db = \Db::getInstance();
        foreach ($this->addedShops as $idShop) {
            $db->delete('shop_url', 'id_shop = ' . $idShop);
            $db->delete('shop', 'id_shop = ' . $idShop);
        }
        foreach ($this->addedShopGroups as $idShopGroup) {
            $db->delete('shop_group', 'id_shop_group = ' . $idShopGroup);
        }
        foreach ($this->savedShareCustomer as $idShopGroup => $share) {
            $db->update('shop_group', ['share_customer' => $share], 'id_shop_group = ' . $idShopGroup);
        }
        if ($this->savedMultishop === false) {
            \Configuration::deleteByName('PS_MULTISHOP_FEATURE_ACTIVE');
        } else {
            \Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', $this->savedMultishop);
        }
        $this->addedShops = [];
        $this->addedShopGroups = [];
        $this->savedShareCustomer = [];
        $this->resetShopCaches();
        if ($this->savedContext > 0) {
            \Shop::setContext($this->savedContext, $this->savedContextId);
        }
    }

    private function defaultShopId(): int
    {
        return (int) \Configuration::get('PS_SHOP_DEFAULT');
    }

    private function addShopGroup(bool $shareCustomer): int
    {
        $db = \Db::getInstance();
        $db->insert('shop_group', [
            'name' => 'Smaily test group',
            'color' => '',
            'share_customer' => (int) $shareCustomer,
            'share_order' => 0,
            'share_stock' => 0,
            'active' => 1,
            'deleted' => 0,
        ]);
        $idShopGroup = (int) $db->Insert_ID();
        $this->addedShopGroups[] = $idShopGroup;
        $this->resetShopCaches();

        return $idShopGroup;
    }

    private function addShop(int $idShopGroup): int
    {
        $db = \Db::getInstance();
        $default = $this->defaultShopId();
        $db->insert('shop', [
            'id_shop_group' => $idShopGroup,
            'name' => 'Smaily test shop',
            'color' => '',
            'id_category' => (int) $db->getValue('SELECT `id_category` FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = ' . $default, false),
            'theme_name' => pSQL((string) $db->getValue('SELECT `theme_name` FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = ' . $default, false)),
            'active' => 1,
            'deleted' => 0,
        ]);
        $idShop = (int) $db->Insert_ID();
        $db->insert('shop_url', [
            'id_shop' => $idShop,
            'domain' => 'shop' . $idShop . '.example.invalid',
            'domain_ssl' => 'shop' . $idShop . '.example.invalid',
            'physical_uri' => '/',
            'virtual_uri' => '',
            'main' => 1,
            'active' => 1,
        ]);
        $this->addedShops[] = $idShop;
        $this->resetShopCaches();

        return $idShop;
    }

    private function setShareCustomer(int $idShopGroup, bool $share): void
    {
        $db = \Db::getInstance();
        if (!array_key_exists($idShopGroup, $this->savedShareCustomer)) {
            $this->savedShareCustomer[$idShopGroup] = (int) $db->getValue(
                'SELECT `share_customer` FROM `' . _DB_PREFIX_ . 'shop_group` WHERE `id_shop_group` = ' . $idShopGroup,
                false
            );
        }
        $db->update('shop_group', ['share_customer' => (int) $share], 'id_shop_group = ' . $idShopGroup);
        $this->resetShopCaches();
    }

    /**
     * Makes this process read the shops, the multishop switch and the configuration again, as the
     * next request would.
     */
    private function resetShopCaches(): void
    {
        \Shop::resetStaticCache();
        \Configuration::resetStaticCache();
    }
}
