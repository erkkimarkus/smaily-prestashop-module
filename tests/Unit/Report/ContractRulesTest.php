<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Integration/Fixtures/mock-subscription/contract.php';

/**
 * The body rules that the tests and the mock receiver hold a report against
 * (tests/Integration/Fixtures/mock-subscription/contract.php) are the contract's: every example
 * body of docs/contracts/installation-report-v1.md passes them, and a body that breaks a rule of
 * the contract's text does not.
 */
final class ContractRulesTest extends TestCase
{
    public function testEveryExampleBodyOfTheContractPasses(): void
    {
        $examples = self::examples();

        self::assertCount(4, $examples, 'WooCommerce, Magento, PrestaShop and Shopify');
        foreach ($examples as $product => $body) {
            self::assertSame([], installation_report_errors($body), $product);
            self::assertSame([], installation_report_unknown_fields($body), $product);
        }
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, array{field: string, code: string}}>
     */
    public function brokenRules(): array
    {
        $set = static fn (array $path, $value): callable => static function (array $body) use ($path, $value): array {
            $at = &$body;
            foreach ($path as $key) {
                $at = &$at[$key];
            }
            $at = $value;

            return $body;
        };
        $drop = static fn (string ...$path): callable => static function (array $body) use ($path): array {
            $at = &$body;
            foreach (array_slice($path, 0, -1) as $key) {
                $at = &$at[is_numeric($key) ? (int) $key : $key];
            }
            unset($at[end($path)]);

            return $body;
        };

        return [
            'a product outside the registry' => [$set(['product'], 'connect_other'), ['field' => 'product', 'code' => 'unknown_product']],
            'no product version' => [$drop('product_version'), ['field' => 'product_version', 'code' => 'required']],
            'a version over 32 characters' => [$set(['product_version'], str_repeat('3', 33)), ['field' => 'product_version', 'code' => 'too_long']],
            'a platform of another product' => [$set(['platforms', 0, 'name'], 'magento'), ['field' => 'platforms[0].name', 'code' => 'invalid_value']],
            'no PHP version' => [$drop('php_version'), ['field' => 'php_version', 'code' => 'required']],
            'an address with a query' => [$set(['installation_url'], 'https://example-store.test/?id=1'), ['field' => 'installation_url', 'code' => 'invalid_value']],
            'an address with credentials' => [$set(['installation_url'], 'https://user:secret@example-store.test'), ['field' => 'installation_url', 'code' => 'invalid_value']],
            'a full host name as the subdomain' => [$set(['smaily_subdomain'], 'example.sendsmaily.net'), ['field' => 'smaily_subdomain', 'code' => 'invalid_value']],
            'a main account that no connection names' => [$set(['smaily_subdomain'], 'elsewhere'), ['field' => 'smaily_subdomain', 'code' => 'invalid_value']],
            'an unknown event' => [$set(['event'], 'removed'), ['field' => 'event', 'code' => 'invalid_value']],
            'a snapshot without a trigger' => [$drop('trigger'), ['field' => 'trigger', 'code' => 'required']],
            'a trigger with an event' => [$set(['event'], 'deactivated'), ['field' => 'trigger', 'code' => 'not_allowed']],
            'a timestamp without a zone' => [$set(['sent_at'], '2026-11-20T07:30:00'), ['field' => 'sent_at', 'code' => 'invalid_value']],
            'a snapshot without a connection' => [$set(['connections'], []), ['field' => 'connections', 'code' => 'required']],
            'a PrestaShop connection without its shop' => [$drop('connections', '1', 'website'), ['field' => 'connections[1].website', 'code' => 'required']],
            'a language that is no two-letter code' => [$set(['connections', 0, 'language'], 'et-EE'), ['field' => 'connections[0].language', 'code' => 'invalid_value']],
            'a feature that is no id' => [$set(['connections', 0, 'features'], ['Subscriber sync']), ['field' => 'connections[0].features[0]', 'code' => 'invalid_value']],
            'a feature named twice' => [$set(['connections', 0, 'features'], ['abandoned_cart', 'abandoned_cart']), ['field' => 'connections[0].features[1]', 'code' => 'duplicate']],
            'no Smaily health' => [$drop('connections', '0', 'smaily'), ['field' => 'connections[0].smaily', 'code' => 'required']],
            'an error message in place of a code' => [$set(['connections', 0, 'smaily'], ['status' => 'failing', 'last_sync_at' => null, 'last_error_code' => 'HTTP 401: Unauthorized']), ['field' => 'connections[0].smaily.last_error_code', 'code' => 'invalid_value']],
            'a failing account without a code' => [$set(['connections', 0, 'smaily', 'status'], 'failing'), ['field' => 'connections[0].smaily.last_error_code', 'code' => 'invalid_value']],
            'a sync time without a zone' => [$set(['connections', 0, 'smaily', 'last_sync_at'], '2026-11-20 07:12:00'), ['field' => 'connections[0].smaily.last_sync_at', 'code' => 'invalid_value']],
            'an engine store without its id' => [$drop('connections', '0', 'intelligence', 'tenant_id'), ['field' => 'connections[0].intelligence.tenant_id', 'code' => 'required']],
            'an engine store whose connected is no boolean' => [$set(['connections', 0, 'intelligence', 'connected'], 'yes'), ['field' => 'connections[0].intelligence.connected', 'code' => 'invalid_type']],
            'two connections of one shop and address' => [$set(['connections', 1], ['role' => 'marketing', 'store_url' => 'https://example-store.test/', 'website' => '1', 'smaily_subdomain' => 'example-second', 'features' => [], 'smaily' => ['status' => 'ok', 'last_sync_at' => null, 'last_error_code' => null]]), ['field' => 'connections[1]', 'code' => 'duplicate']],
        ];
    }

    /**
     * @dataProvider brokenRules
     *
     * @param callable(array<string, mixed>): array<string, mixed> $break
     * @param array{field: string, code: string} $error
     */
    public function testABodyThatBreaksARuleOfTheContractIsRefused(callable $break, array $error): void
    {
        self::assertContains($error, installation_report_errors($break(self::examples()['connect_prestashop'])));
    }

    public function testADisconnectedReportMayCarryNoConnectionAndAFieldTheContractDoesNotNameIsListed(): void
    {
        $body = self::examples()['connect_prestashop'];
        unset($body['trigger']);
        $body['event'] = 'disconnected';
        $body['connections'] = [];
        self::assertSame([], installation_report_errors($body));

        $body = self::examples()['connect_prestashop'];
        $body['admin_email'] = 'admin@example.test';
        $body['connections'][0]['smaily']['last_error_message'] = 'x';
        self::assertSame(['admin_email', 'connections[0].smaily.last_error_message'], installation_report_unknown_fields($body));
    }

    /**
     * The JSON bodies of the contract's "Example" sections, by product.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function examples(): array
    {
        $contract = (string) file_get_contents(__DIR__ . '/../../../docs/contracts/installation-report-v1.md');
        preg_match_all('/^### Example — [^\n]+\n(?:(?!^```json$).)*?^```json\n(.*?)^```$/ms', $contract, $matches);
        $examples = [];
        foreach ($matches[1] as $json) {
            $body = json_decode($json, true);
            self::assertIsArray($body);
            $examples[$body['product']] = $body;
        }

        return $examples;
    }
}
