<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;

/**
 * The Estonian catalogues of ARCHITECTURE 10.4: every string that a template, a script's label
 * or a translate call words has a unit, and every unit is one that PrestaShop finds (its id is the
 * MD5 of its source) and that keeps the placeholders of its source.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const ADMIN = 'Admin';

    private const SHOP = 'Shop';

    public function testEveryStringOfATemplateHasAnEstonianUnit(): void
    {
        $admin = self::sources(self::ADMIN);
        $shop = self::sources(self::SHOP);
        $missing = [];
        $templates = 0;
        foreach (self::files('views', ['twig', 'tpl']) as $path => $text) {
            ++$templates;
            // Twig: 'text'|trans in the module's own domain; a call that names another domain
            // (PrestaShop's own words) is not the module's to translate.
            preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'\s*\|\s*trans(?:\(\s*\{[^}]*\}\s*,\s*\'([^\']+)\')?/', $text, $twig, PREG_SET_ORDER);
            foreach (substr($path, -5) === '.twig' ? $twig : [] as $match) {
                if (isset($match[2]) && $match[2] !== 'Modules.Smailyforprestashop.Admin') {
                    continue;
                }
                if (!isset($admin[self::unescape($match[1])])) {
                    $missing[] = $path . ': ' . $match[1];
                }
            }
            // Smarty: {l s='text' d='Modules.Smailyforprestashop.Shop'}.
            preg_match_all('/\{l\s+s=\'((?:[^\'\\\\]|\\\\.)*)\'\s+d=\'Modules\.Smailyforprestashop\.Shop\'/', $text, $smarty, PREG_SET_ORDER);
            foreach ($smarty as $match) {
                if (!isset($shop[self::unescape($match[1])])) {
                    $missing[] = $path . ': ' . $match[1];
                }
            }
        }

        self::assertGreaterThan(40, $templates, 'the templates were read');
        self::assertSame([], $missing, 'a template string without an Estonian unit shows in English');
    }

    public function testEveryStringOfATranslateCallHasAnEstonianUnit(): void
    {
        $known = self::sources(self::ADMIN) + self::sources(self::SHOP);
        $missing = [];
        $calls = 0;
        $code = self::files('src', ['php']) + self::files('controllers', ['php']) + self::files('smailyforprestashop.php', ['php']);
        foreach ($code as $path => $text) {
            preg_match_all('/\btrans\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $text, $matches);
            foreach ($matches[1] as $literal) {
                ++$calls;
                if (!isset($known[self::unescape($literal)])) {
                    $missing[] = $path . ': ' . $literal;
                }
            }
        }
        foreach ([...array_values(FailureMessage::REASONS + FailureMessage::JOB_REASONS), ...FailureMessage::MESSAGES] as $sentence) {
            ++$calls;
            if (!isset($known[$sentence])) {
                $missing[] = 'FailureMessage: ' . $sentence;
            }
        }

        self::assertGreaterThan(200, $calls, 'the translate calls were read');
        self::assertSame([], $missing, 'a worded string without an Estonian unit shows in English');
    }

    /**
     * @dataProvider catalogues
     */
    public function testEveryUnitIsFoundByItsSourceAndKeepsItsPlaceholders(string $catalogue): void
    {
        $wrong = [];
        $units = 0;
        foreach (self::units($catalogue) as $unit) {
            ++$units;
            $source = (string) $unit->source;
            $target = (string) $unit->target;
            if ((string) $unit['id'] !== md5($source)) {
                $wrong[] = 'id: ' . $source;
            }
            if (trim($target) === '') {
                $wrong[] = 'no target: ' . $source;
            }
            if (self::placeholders($source) !== self::placeholders($target)) {
                $wrong[] = 'placeholders: ' . $source;
            }
        }

        self::assertGreaterThan(0, $units);
        self::assertSame([], $wrong);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogues(): iterable
    {
        yield 'back office' => [self::ADMIN];
        yield 'storefront' => [self::SHOP];
    }

    /**
     * @return array<string, true> the sources of a catalogue
     */
    private static function sources(string $catalogue): array
    {
        $sources = [];
        foreach (self::units($catalogue) as $unit) {
            $sources[(string) $unit->source] = true;
        }

        return $sources;
    }

    /**
     * @return iterable<\SimpleXMLElement>
     */
    private static function units(string $catalogue): iterable
    {
        $file = simplexml_load_file(self::module() . 'translations/et-EE/ModulesSmailyforprestashop' . $catalogue . '.et-EE.xlf');
        self::assertNotFalse($file);

        return $file->file->body->{'trans-unit'};
    }

    /**
     * @param list<string> $extensions
     *
     * @return array<string, string> the text of each file under a path of the module, by its path
     */
    private static function files(string $path, array $extensions): array
    {
        $root = self::module();
        if (is_file($root . $path)) {
            return [$path => (string) file_get_contents($root . $path)];
        }
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $path, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $found[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%[a-z_0-9]+%/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }

    private static function unescape(string $literal): string
    {
        return str_replace(['\\\'', '\\\\'], ['\'', '\\'], $literal);
    }

    private static function module(): string
    {
        return dirname(__DIR__, 3) . '/src/';
    }
}
