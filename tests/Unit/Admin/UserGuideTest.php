<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\UserGuide;

/**
 * The merchant guide's address is set in one place (Admin\UserGuide), every section the admin
 * deep-links to is an id on the guide page (docs/site/index.html), and no admin page links the
 * repository's USERGUIDE.md.
 */
final class UserGuideTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testTheGuideIsTheSiblingsSite(): void
    {
        self::assertSame('https://smaily.com/connect-presta/', UserGuide::URL);
    }

    public function testEverySectionTheAdminLinksToIsOnTheGuidePage(): void
    {
        $page = (string) file_get_contents(self::ROOT . '/docs/site/index.html');
        $sections = array_filter(
            (new \ReflectionClass(UserGuide::class))->getConstants(),
            static fn (string $name): bool => str_starts_with($name, 'SECTION_'),
            ARRAY_FILTER_USE_KEY
        );

        self::assertNotEmpty($sections);
        foreach ($sections as $name => $id) {
            self::assertSame(1, substr_count($page, 'id="' . $id . '"'), $name . ' is one id on the guide page');
        }
    }

    public function testASectionsLinkIsTheGuidesAddressAndTheSection(): void
    {
        self::assertSame('https://smaily.com/connect-presta/#set-storefront-recs', UserGuide::link(UserGuide::SECTION_STOREFRONT_RECOMMENDATIONS));
    }

    public function testTheRecommendationsCardLinksTheGuideThroughUserGuide(): void
    {
        $template = (string) file_get_contents(self::ROOT . '/src/views/templates/admin/panel/intelligence.html.twig');
        $controller = (string) file_get_contents(self::ROOT . '/src/src/Admin/Settings/SettingsController.php');

        self::assertStringContainsString('<a href="{{ guide }}"', $template);
        self::assertStringContainsString("'intelligence_guide' => UserGuide::link(UserGuide::SECTION_STOREFRONT_RECOMMENDATIONS)", $controller);
    }

    public function testNoAdminPageLinksTheRepositorysUserGuide(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src/views/templates', \FilesystemIterator::SKIP_DOTS));
        $linking = [];
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'USERGUIDE.md')) {
                $linking[] = $file->getFilename();
            }
        }

        self::assertSame([], $linking);
    }
}
