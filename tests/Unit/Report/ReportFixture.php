<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowMatch;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowResolverInterface;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Report\StoreFacts;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use Tests\Unit\Contacts\FakeContactsSettings;
use Tests\Unit\Scope\FakeStoreLayout;

require_once __DIR__ . '/FakeReportSettings.php';
require_once __DIR__ . '/../Scope/FakeStoreLayout.php';
require_once __DIR__ . '/../Contacts/FakeContactsSettings.php';

/**
 * A store held in memory: shop id => address, and the languages of each shop.
 */
final class FakeStoreFacts extends StoreFacts
{
    /** @var array<int, string> */
    private array $shops;

    /** @var array<int, list<string>> */
    private array $languages;

    private int $default;

    /**
     * @param array<int, string> $shops
     * @param array<int, list<string>> $languages
     */
    public function __construct(array $shops, array $languages = [], ?int $default = null)
    {
        $this->shops = $shops;
        $this->languages = $languages;
        $this->default = $default ?? (int) array_key_first($shops);
    }

    public function shopIds(): array
    {
        return array_keys($this->shops);
    }

    public function defaultShopId(): int
    {
        return $this->default;
    }

    public function url(int $idShop): string
    {
        return $this->shops[$idShop] ?? '';
    }

    public function languages(int $idShop): array
    {
        return $this->languages[$idShop] ?? ['en'];
    }

    public function platformVersion(): string
    {
        return '8.2.8';
    }
}

/**
 * Records each request and answers them in turn with the given responses or failures; the last
 * one answers every later request.
 */
final class ReportTransport implements Transport
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $answers;

    /**
     * @param Response|\Throwable ...$answers
     */
    public function __construct(...$answers)
    {
        $this->answers = $answers !== [] ? array_values($answers) : [new Response(200, '{"installation_id": "3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"}')];
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = [$method, $url, $options];
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * The decoded body of a request.
     *
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index][2]['body'], true);
    }
}

/**
 * Builds a ReportBuilder over a store held in memory.
 */
final class ReportFixture
{
    public const NOW = 1763622000; // 2025-11-20T07:00:00Z

    public const PASSWORD = 'not-a-real-password';

    /** @var array<int, array<string, string>> the layout's settings: id_shop => key => value */
    public array $layout = [0 => [StoreLayout::SEPARATE_STORES => '0']];

    /** @var array<int, string> shop id => address */
    public array $shops = [1 => 'https://example-store.test/'];

    /** @var array<int, list<string>> */
    public array $languages = [];

    /** @var list<Account> */
    public array $accounts = [];

    /** @var array<int, array<string, string>> the Contacts settings: id_shop => key => value */
    public array $contacts = [];

    /** @var array<string, Account> "<trigger>|<id_shop>|<language>" => the account whose workflow is mapped */
    public array $workflows = [];

    /** @var array<int, Connection> the Campaign Intelligence connection that serves each shop */
    public array $engine = [];

    /** @var array<int, bool> a connection's id_shop => the merchant confirmed the cookie banner */
    public array $consent = [];

    public string $moduleVersion = '3.0.0';

    public FakeReportSettings $settings;

    public ConnectionHealth $health;

    public int $now = self::NOW;

    public function __construct()
    {
        $this->settings = new FakeReportSettings();
        $this->health = new ConnectionHealth($this->settings, fn (): int => $this->now);
    }

    public static function account(int $idShop, string $language, string $subdomain, string $password = self::PASSWORD): Account
    {
        return new Account($idShop, $language, $subdomain, 'api-user-' . $subdomain, $password);
    }

    public function builder(): ReportBuilder
    {
        $layout = new FakeStoreLayout($this->layout);
        $find = function (int $idShop, string $language): ?Account {
            foreach ($this->accounts as $account) {
                if ($account->idShop() === $idShop && $account->language() === $language) {
                    return $account;
                }
            }

            return null;
        };
        $router = new class($this) implements WorkflowResolverInterface {
            private ReportFixture $fixture;

            public function __construct(ReportFixture $fixture)
            {
                $this->fixture = $fixture;
            }

            public function resolve(string $trigger, int $idShop, string $language): ?WorkflowMatch
            {
                $account = $this->fixture->workflows[$trigger . '|' . $idShop . '|' . $language] ?? null;

                return $account === null ? null : new WorkflowMatch($account, 'default', 11, $idShop, $language, false);
            }
        };

        return new ReportBuilder(
            new FakeStoreFacts($this->shops, $this->languages),
            $layout,
            new AccountResolver($layout, $find),
            new FakeContactsSettings($this->contacts),
            $router,
            fn (int $idShop): ?Connection => $this->engine[$idShop] ?? null,
            fn (int $idShop): bool => $this->consent[$idShop] ?? false,
            $this->health,
            $this->moduleVersion,
            fn (): int => $this->now
        );
    }

    /**
     * The settings of an active contact sync.
     *
     * @return array<string, string>
     */
    public static function syncOn(): array
    {
        return [ContactsSettings::ENABLED => '1', ContactsSettings::CONFIRMED => '1'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function connection(int $id, int $idShop, string $tenantId, string $apiKey = 'sk_not_a_real_key', array $settings = []): Connection
    {
        return new Connection($id, $idShop, 'example', $tenantId, $apiKey, $settings);
    }
}
