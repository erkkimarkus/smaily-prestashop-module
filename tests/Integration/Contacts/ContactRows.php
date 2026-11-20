<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

/**
 * Seeds invented customers and newsletter-block rows straight into the shop's tables, so no hook
 * fires, and deletes them again in tearDown() with removeContactRows().
 */
trait ContactRows
{
    /** @var list<int> */
    private array $addedCustomers = [];

    /** @var list<int> */
    private array $addedNewsletterRows = [];

    /**
     * @param array<string, mixed> $columns more `customer` columns
     */
    private function addCustomer(int $idShop, string $email, bool $newsletter, int $idLang, array $columns = []): int
    {
        $db = \Db::getInstance();
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('customer', $columns + [
            'id_shop_group' => (int) \Shop::getGroupFromShop($idShop),
            'id_shop' => $idShop,
            'id_gender' => 0,
            'id_lang' => $idLang,
            'firstname' => 'Testfirst',
            'lastname' => 'Testlast',
            'email' => pSQL($email),
            'passwd' => 'not-a-real-hash',
            'newsletter' => (int) $newsletter,
            'active' => 1,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $id = (int) $db->Insert_ID();
        $this->addedCustomers[] = $id;

        return $id;
    }

    private function addNewsletterRow(int $idShop, string $email, bool $active, int $idLang): int
    {
        $db = \Db::getInstance();
        $db->insert('emailsubscription', [
            'id_shop' => $idShop,
            'id_shop_group' => (int) \Shop::getGroupFromShop($idShop),
            'email' => pSQL($email),
            'newsletter_date_add' => gmdate('Y-m-d H:i:s'),
            'ip_registration_newsletter' => '127.0.0.1',
            'active' => (int) $active,
            'id_lang' => $idLang,
        ]);
        $id = (int) $db->Insert_ID();
        $this->addedNewsletterRows[] = $id;

        return $id;
    }

    private function newsletter(int $idCustomer): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `newsletter` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . $idCustomer,
            false
        );
    }

    private function active(int $idNewsletterRow): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `active` FROM `' . _DB_PREFIX_ . 'emailsubscription` WHERE `id` = ' . $idNewsletterRow,
            false
        );
    }

    private function removeContactRows(): void
    {
        $db = \Db::getInstance();
        foreach (array_chunk($this->addedCustomers, 500) as $ids) {
            $db->delete('customer', 'id_customer IN (' . implode(',', $ids) . ')');
        }
        foreach (array_chunk($this->addedNewsletterRows, 500) as $ids) {
            $db->delete('emailsubscription', 'id IN (' . implode(',', $ids) . ')');
        }
        $this->addedCustomers = [];
        $this->addedNewsletterRows = [];
    }
}
