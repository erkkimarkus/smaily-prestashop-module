<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;

/**
 * A JSON value is stored without a raw `<`, `>` or `&`, which PrestaShop strips or rewrites in a
 * configuration value, and reads back exactly as it was written.
 */
final class SettingsJsonTest extends TestCase
{
    private const KEY = Settings::PREFIX . 'TEST_PROBE';

    public function testAValueWithTagsAndAnAmpersandRoundTripsExactly(): void
    {
        $settings = new JsonProbeSettings();
        $value = ['text' => '<b>x</b> & y', 'list' => ['<i>', '&amp;']];

        self::assertTrue($settings->writeJson(self::KEY, $value));

        $stored = (string) $settings->values[self::KEY];
        self::assertStringNotContainsString('<', $stored);
        self::assertStringNotContainsString('>', $stored);
        self::assertStringNotContainsString('&', $stored);
        self::assertSame($value, $settings->readJson(self::KEY));
    }

    public function testAValueStoredWithoutTheEscapesStillReads(): void
    {
        $settings = new JsonProbeSettings();
        $settings->values[self::KEY] = '{"version":"3.0.0","dismissed":null,"list":["a/b",1]}';

        self::assertSame(['version' => '3.0.0', 'dismissed' => null, 'list' => ['a/b', 1]], $settings->readJson(self::KEY));
    }
}

/**
 * Settings held in memory at the global scope.
 */
final class JsonProbeSettings extends Settings
{
    /** @var array<string, string> */
    public array $values = [];

    protected function get(string $key, int $idShop = 0): ?string
    {
        return $this->values[$key] ?? null;
    }

    protected function set(string $key, string $value, int $idShop = 0): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    /**
     * @return array<mixed>
     */
    public function readJson(string $key): array
    {
        return $this->getJson($key);
    }

    /**
     * @param array<mixed> $value
     */
    public function writeJson(string $key, array $value): bool
    {
        return $this->setJson($key, $value);
    }
}
