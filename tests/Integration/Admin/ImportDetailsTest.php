<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\ImportDetails;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\TransportException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\IntegrationTestCase;

/**
 * An import card's "Details for your developer" shows the import's stored reason in the admin's
 * language: where a stopped import stopped (Failure::location(), Magento's ErrorLocation), a
 * reason code as its sentence, and a client's sentence that an import stored before as the Log
 * shows it. Nothing is added where no import ran.
 */
final class ImportDetailsTest extends IntegrationTestCase
{
    public function testAStoppedContactImportShowsWhereItStoppedInTheAdminsLanguage(): void
    {
        $location = Failure::location(new TransportException('Smaily API request failed with HTTP 503', 503));

        $progress = ImportDetails::contacts(['status' => 'failed', 'last_error' => $location], self::translator());

        self::assertStringStartsWith('[Modules.Smailyforprestashop.Admin] ' . TransportException::class . ' at ImportDetailsTest.php:', $progress['details']);
        self::assertStringEndsWith(' (code 503)', $progress['details']);
        self::assertSame($location, $progress['last_error'], 'the stored reason stays as stored');
    }

    public function testAReasonStoredBeforeShowsAsTheLogShowsIt(): void
    {
        $translator = self::translator();

        self::assertSame(
            '[Modules.Smailyforprestashop.Admin] Smaily API request failed with HTTP 503',
            ImportDetails::contacts(['last_error' => 'Smaily API request failed with HTTP 503'], $translator)['details']
        );
        self::assertSame('', ImportDetails::contacts(['last_error' => null], $translator)['details']);
        self::assertNull(ImportDetails::contacts(null, $translator));
    }

    public function testEachCampaignIntelligenceImportGetsItsDetails(): void
    {
        $imports = ImportDetails::intelligence(
            ['catalog' => ['reason' => 'no_connection'], 'customers' => null, 'orders' => ['reason' => null]],
            self::translator()
        );

        self::assertSame(
            ['catalog' => '[Modules.Smailyforprestashop.Admin] Cancelled: the Campaign Intelligence connection of this import no longer exists.', 'customers' => null, 'orders' => ''],
            array_map(static fn (?array $job): ?string => $job === null ? null : $job['details'], $imports)
        );
        self::assertNull(ImportDetails::intelligence(null, self::translator()));
    }

    /**
     * A translator that names the domain it was asked in and fills the values in.
     */
    private static function translator(): TranslatorInterface
    {
        return new class() implements TranslatorInterface {
            public function trans($id, array $parameters = [], $domain = null, $locale = null): string
            {
                return '[' . $domain . '] ' . strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'et';
            }
        };
    }
}
