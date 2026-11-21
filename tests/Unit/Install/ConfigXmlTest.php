<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * The release zip carries the config.xml that PrestaShop writes from the main class. PrestaShop reads
 * the file instead of the class while the file is the newer of the two, so a config.xml that lags
 * behind the class shows an old name or version. The main class extends PrestaShop's Module, so the
 * test reads its source.
 */
final class ConfigXmlTest extends TestCase
{
    public function testConfigXmlHoldsTheValuesOfTheMainClass(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/smailyforprestashop.php');
        $xml = simplexml_load_string((string) file_get_contents(__DIR__ . '/../../../src/config.xml'));
        self::assertNotFalse($xml);

        foreach (['name', 'version', 'author', 'tab'] as $property) {
            self::assertSame(1, preg_match('/\$this->' . $property . ' = \'([^\']*)\';/', $source, $match), $property);
            self::assertSame($match[1], (string) $xml->{$property}, $property);
        }
        foreach (['displayName', 'description', 'confirmUninstall'] as $property) {
            self::assertSame(1, preg_match('/\$this->' . $property . ' = \$this->trans\(\'([^\']*)\',/', $source, $match), $property);
            self::assertSame($match[1], (string) $xml->{$property}, $property);
        }
        self::assertSame(1, preg_match('/\$this->need_instance = (\d);/', $source, $match));
        self::assertSame($match[1], (string) $xml->need_instance);
    }
}
