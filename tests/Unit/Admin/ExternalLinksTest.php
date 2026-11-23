<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * A link of the module's admin pages that leaves the back office opens in a new tab, as the
 * sibling connectors' links do: the merchant keeps the page and what is typed on it. Such a link
 * carries `target="_blank"` and a `rel` with `noopener`.
 *
 * A link stays in the back office when its address is a route of the shop (`path(…)`) or when a
 * script of the page handles it (`data-smaily-action`). Every other link leaves, also one whose
 * address a script fills in later.
 */
final class ExternalLinksTest extends TestCase
{
    public function testEveryLinkThatLeavesTheBackOfficeOpensInANewTab(): void
    {
        $leaving = [];
        $wrong = [];
        foreach (self::templates() as $path => $text) {
            // A Twig expression inside the tag may hold a ">".
            preg_match_all('/<a\b((?:\{\{.*?\}\}|\{%.*?%\}|[^>])*)>/s', $text, $links);
            foreach ($links[1] as $attributes) {
                if (preg_match('/\bhref="((?:\{\{.*?\}\}|[^"])*)"/s', $attributes, $href) !== 1) {
                    continue;
                }
                if (str_contains($href[1], 'path(') || str_contains($attributes, 'data-smaily-action=')) {
                    continue;
                }
                $leaving[] = $path . ': ' . $href[1];
                $newTab = preg_match('/\btarget="_blank"/', $attributes) === 1;
                $noOpener = preg_match('/\brel="[^"]*\bnoopener\b[^"]*"/', $attributes) === 1;
                if (!$newTab || !$noOpener) {
                    $wrong[] = $path . ': ' . $href[1];
                }
            }
        }

        // The invitation's "Create a Smaily account", "Contact Smaily", the update notice's
        // "Download" and "What's new", the user guide and "Open your Smaily account".
        self::assertGreaterThanOrEqual(6, count($leaving), 'the links that leave were found');
        self::assertSame([], $wrong, 'a link that leaves the back office opens in the same tab');
    }

    /**
     * @return array<string, string> the admin templates by their path under the templates folder
     */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 3) . '/src/views/templates/admin';
        $templates = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'twig') {
                $templates[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($templates);

        return $templates;
    }
}
