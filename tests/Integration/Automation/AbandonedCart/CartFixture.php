<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

/**
 * Customers, carts and orders of invented shoppers in the docker shop, for the abandoned-cart
 * tests, and their removal. A cart holds demo products; its last change is set back in time
 * instead of waiting for the delay.
 */
final class CartFixture
{
    /** @var list<int> */
    private array $customers = [];

    /** @var list<int> */
    private array $carts = [];

    /** @var list<int> */
    private array $orders = [];

    public function customer(string $email, bool $guest = false, bool $newsletter = false, string $newsletterSince = '2020-01-01 00:00:00', int $idShop = 0): int
    {
        $customer = new \Customer();
        $customer->firstname = 'Testfirst';
        $customer->lastname = 'Testlast';
        $customer->email = $email;
        $customer->passwd = (new \PrestaShop\PrestaShop\Core\Crypto\Hashing())->hash('not-a-real-password');
        $customer->is_guest = $guest;
        $customer->newsletter = $newsletter;
        $customer->id_shop = $idShop > 0 ? $idShop : (int) \Configuration::get('PS_SHOP_DEFAULT');
        $customer->id_shop_group = (int) \Shop::getGroupFromShop($customer->id_shop);
        if (!$customer->add()) {
            throw new \RuntimeException('The test customer was not saved.');
        }
        if ($newsletter) {
            \Db::getInstance()->update('customer', ['newsletter_date_add' => pSQL($newsletterSince)], '`id_customer` = ' . (int) $customer->id);
        }
        $this->customers[] = (int) $customer->id;

        return (int) $customer->id;
    }

    /**
     * A cart of $products demo products (1 to 3 pieces each) whose last change was $minutesAgo minutes
     * ago; created $createdMinutesAgo minutes ago (default: with the last change).
     */
    public function cart(int $idCustomer, int $minutesAgo, int $products = 2, int $idShop = 0, int $createdMinutesAgo = 0): int
    {
        $idShop = $idShop > 0 ? $idShop : (int) \Configuration::get('PS_SHOP_DEFAULT');
        $cart = new \Cart();
        $cart->id_customer = $idCustomer;
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_shop = $idShop;
        $cart->id_shop_group = (int) \Shop::getGroupFromShop($idShop);
        $cart->secure_key = $idCustomer > 0 ? (string) (new \Customer($idCustomer))->secure_key : md5('test-cart');
        if (!$cart->add()) {
            throw new \RuntimeException('The test cart was not saved.');
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_product`, `cache_default_attribute` FROM `' . _DB_PREFIX_ . 'product` WHERE `active` = 1 ORDER BY `id_product` LIMIT ' . $products
        ) ?: [];
        if (count($rows) < $products) {
            throw new \RuntimeException('The demo shop has fewer products than the test cart needs.');
        }
        foreach ($rows as $i => $row) {
            \Db::getInstance()->insert('cart_product', [
                'id_cart' => (int) $cart->id,
                'id_product' => (int) $row['id_product'],
                'id_address_delivery' => 0,
                'id_shop' => $idShop,
                'id_product_attribute' => (int) $row['cache_default_attribute'],
                'id_customization' => 0,
                'quantity' => 1 + $i % 3,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->carts[] = (int) $cart->id;
        $this->age((int) $cart->id, $minutesAgo, $createdMinutesAgo > 0 ? $createdMinutesAgo : $minutesAgo);

        return (int) $cart->id;
    }

    public function age(int $idCart, int $minutesAgo, ?int $createdMinutesAgo = null): void
    {
        $data = ['date_upd' => date('Y-m-d H:i:s', time() - 60 * $minutesAgo)];
        if ($createdMinutesAgo !== null) {
            $data['date_add'] = date('Y-m-d H:i:s', time() - 60 * $createdMinutesAgo);
        }
        \Db::getInstance()->update('cart', $data, '`id_cart` = ' . $idCart);
    }

    /**
     * Places an order for the cart through PrestaShop's PaymentModule::validateOrder(), so the
     * order hooks fire as at a real checkout.
     */
    public function order(int $idCart): int
    {
        $cart = new \Cart($idCart);
        $customer = new \Customer((int) $cart->id_customer);
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $address->alias = 'Test';
        $address->firstname = 'Testfirst';
        $address->lastname = 'Testlast';
        $address->address1 = 'Test street 1';
        $address->city = 'Testcity';
        $address->postcode = '10115';
        $address->add();
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->update();
        \Db::getInstance()->update('cart_product', ['id_address_delivery' => (int) $address->id], '`id_cart` = ' . $idCart);

        $context = \Context::getContext();
        $saved = [$context->cart, $context->customer, $context->currency];
        $context->cart = $cart;
        $context->customer = $customer;
        $context->currency = new \Currency((int) $cart->id_currency);
        try {
            /** @var \PaymentModule $payment */
            $payment = \Module::getInstanceByName('ps_wirepayment');
            $payment->validateOrder(
                $idCart,
                (int) \Configuration::get('PS_OS_BANKWIRE'),
                (float) $cart->getOrderTotal(true, \Cart::BOTH),
                'Bank wire',
                null,
                [],
                null,
                false,
                $customer->secure_key
            );
        } finally {
            [$context->cart, $context->customer, $context->currency] = $saved;
        }
        $idOrder = (int) \Order::getIdByCartId($idCart);
        $this->orders[] = $idOrder;

        return $idOrder;
    }

    public function remove(): void
    {
        foreach ($this->orders as $idOrder) {
            (new \Order($idOrder))->delete();
        }
        foreach ($this->carts as $idCart) {
            \Db::getInstance()->delete('cart_product', '`id_cart` = ' . $idCart);
            \Db::getInstance()->delete('cart', '`id_cart` = ' . $idCart);
        }
        foreach ($this->customers as $idCustomer) {
            \Db::getInstance()->delete('address', '`id_customer` = ' . $idCustomer);
            \Db::getInstance()->delete('customer', '`id_customer` = ' . $idCustomer);
        }
        $this->customers = $this->carts = $this->orders = [];
    }
}
