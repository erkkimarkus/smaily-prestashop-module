<?php
declare(strict_types=1);

namespace Tests\Integration\Discount;

use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Discount\CodeIssuer;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use Tests\Integration\Automation\AbandonedCart\AbandonedCartTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Automation/AbandonedCart/AbandonedCartTestCase.php';

/**
 * The store of AbandonedCartTestCase (one Smaily account that the mock accepts, the abandoned-cart
 * automation on with workflow 102) with the welcome (workflow 101) and the first order (workflow
 * 103) on too, and cart rules of its own: template() makes a merchant's rule, and tearDown()
 * removes the rules the test made and every code that was issued.
 */
abstract class DiscountTestCase extends AbandonedCartTestCase
{
    /** @var list<int> */
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();
        $mappings = new MappingRepository();
        $mappings->upsert(0, Trigger::WELCOME, '', MappingRepository::ACCOUNT_DEFAULT, 101);
        $mappings->upsert(0, Trigger::FIRST_ORDER, '', MappingRepository::ACCOUNT_DEFAULT, 103);
        $settings = new AutomationSettings();
        $settings->setEnabled(Trigger::WELCOME, true, 0);
        $settings->setEnabled(Trigger::FIRST_ORDER, true, 0);
        LegacyStore::reloadConfiguration();
        $this->services = $this->services();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach (array_merge($this->rules, self::issuedIds()) as $id) {
                (new \CartRule($id))->delete();
            }
        }
        parent::tearDown();
    }

    /**
     * A cart rule as a merchant makes it: 10 % off, disabled, without a code, valid for a month,
     * named in every language; $columns replace what the rule holds.
     *
     * @param array<string, mixed> $columns
     */
    protected function template(array $columns = []): int
    {
        $rule = new \CartRule();
        foreach (array_map('intval', \Language::getIDs(false)) as $idLang) {
            $rule->name[$idLang] = 'Ten percent ' . \Language::getIsoById($idLang);
        }
        $rule->description = 'The merchant\'s own note';
        $rule->date_from = date('Y-m-d H:i:s', time() - 3600);
        $rule->date_to = date('Y-m-d H:i:s', time() + 30 * 86400);
        $rule->reduction_percent = 10;
        $rule->quantity = 100;
        $rule->quantity_per_user = 5;
        $rule->highlight = true;
        $rule->active = false;
        foreach ($columns as $name => $value) {
            $rule->{$name} = $value;
        }
        self::assertTrue($rule->add());
        $this->rules[] = (int) $rule->id;

        return (int) $rule->id;
    }

    /**
     * Switches an automation's discount code on, from the rule, for the days.
     */
    protected function discount(string $trigger, int $idRule, int $days = 7, int $idShop = 0): void
    {
        (new AutomationSettings())->setDiscount($trigger, true, $idRule, $days, $idShop);
        LegacyStore::reloadConfiguration();
        $this->services = $this->services();
    }

    protected function queue(): EventQueue
    {
        return $this->services->get(QueueProvider::EVENT_QUEUE);
    }

    /**
     * Queues a welcome or first-order row as its trigger does.
     *
     * @param array<string, mixed> $fields
     */
    protected function automationRow(string $eventType, string $email, array $fields = ['first_name' => 'Testfirst']): int
    {
        return $this->queue()->enqueue($eventType, $this->idShop, ['email' => $email, 'fields' => $fields], $email, 'en');
    }

    /**
     * @return array<string, string|null>
     */
    protected static function stored(int $id): array
    {
        return self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $id, false);
    }

    /**
     * The address that the mock received with its $index-th automation post.
     *
     * @return array<string, mixed>
     */
    protected function posted(int $index): array
    {
        return $this->reminders()[$index]['body']['addresses'][0];
    }

    /**
     * The cart rules that carry the module's marker, oldest first.
     *
     * @return list<int>
     */
    protected static function issuedIds(): array
    {
        $rows = self::db()->executeS('SELECT cr.`id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule` cr WHERE ' . CodeIssuer::issued('cr') . ' ORDER BY cr.`id_cart_rule`', true, false);

        return array_map(static fn (array $row): int => (int) $row['id_cart_rule'], $rows ?: []);
    }
}
