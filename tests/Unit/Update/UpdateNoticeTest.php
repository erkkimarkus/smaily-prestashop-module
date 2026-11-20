<?php
declare(strict_types=1);

namespace Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Update\UpdateNotice;

require_once __DIR__ . '/FakeUpdateSettings.php';

/**
 * The notice that a newer version is out: shown while the latest known release is newer than the
 * installed module and newer than the version the merchant dismissed.
 */
final class UpdateNoticeTest extends TestCase
{
    public function testNoNoticeBeforeAnyCheck(): void
    {
        self::assertNull(self::notice(new FakeUpdateSettings())->current());
    }

    public function testANewerReleaseIsTheNotice(): void
    {
        $settings = new FakeUpdateSettings();
        self::notice($settings)->record(self::release('3.1.0'), 1763640000);

        self::assertSame(self::release('3.1.0'), self::notice($settings)->current());
        self::assertSame([
            'version' => '3.1.0',
            'download_url' => self::release('3.1.0')['download_url'],
            'release_url' => self::release('3.1.0')['release_url'],
            'checked_at' => 1763640000,
            'dismissed' => null,
        ], json_decode((string) $settings->value, true));
    }

    /**
     * @dataProvider notNewer
     */
    public function testASameOrOlderReleaseIsNoNotice(string $latest, string $installed): void
    {
        $settings = new FakeUpdateSettings();
        self::notice($settings)->record(self::release($latest), 1763640000);

        self::assertNull(self::notice($settings, $installed)->current());
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function notNewer(): iterable
    {
        yield 'the same version' => ['3.0.0', '3.0.0'];
        yield 'an older version' => ['2.2.0', '3.0.0'];
        yield 'the store runs a newer build' => ['3.0.9', '3.0.10'];
    }

    public function testAStoreOnAReleaseCandidateSeesTheRelease(): void
    {
        $settings = new FakeUpdateSettings();
        self::notice($settings)->record(self::release('3.0.0'), 1763640000);

        self::assertSame('3.0.0', self::notice($settings, '3.0.0-rc1')->current()['version'] ?? null);
    }

    public function testADismissedVersionIsNoNoticeAndTheNextVersionIs(): void
    {
        $settings = new FakeUpdateSettings();
        $notice = self::notice($settings);
        $notice->record(self::release('3.1.0'), 1763640000);

        $notice->dismiss('3.1.0');

        self::assertNull($notice->current());
        $notice->record(self::release('3.1.0'), 1763726400);
        self::assertNull($notice->current(), 'a later check of the same version keeps it dismissed');

        $notice->record(self::release('3.1.1'), 1763812800);

        self::assertSame(self::release('3.1.1'), $notice->current());
    }

    public function testDismissingAnOlderVersionKeepsTheNewerNotice(): void
    {
        $settings = new FakeUpdateSettings();
        $notice = self::notice($settings);
        $notice->record(self::release('3.1.1'), 1763640000);

        $notice->dismiss('3.1.0');

        self::assertSame('3.1.1', $notice->current()['version'] ?? null);
    }

    public function testADismissalWithATagPrefixCounts(): void
    {
        $settings = new FakeUpdateSettings();
        $notice = self::notice($settings);
        $notice->record(self::release('3.1.0'), 1763640000);

        $notice->dismiss('v3.1.0');

        self::assertNull($notice->current());
    }

    public function testADismissalOfSomethingThatIsNoVersionChangesNothing(): void
    {
        $settings = new FakeUpdateSettings();
        $notice = self::notice($settings);
        $notice->record(self::release('3.1.0'), 1763640000);
        $stored = $settings->value;

        $notice->dismiss('<script>');

        self::assertSame($stored, $settings->value);
    }

    public function testADismissalBeforeAnyCheckIsKeptByTheNextCheck(): void
    {
        $settings = new FakeUpdateSettings();
        $notice = self::notice($settings);
        $notice->dismiss('3.1.0');

        $notice->record(self::release('3.1.0'), 1763640000);

        self::assertNull($notice->current());
    }

    /**
     * @dataProvider unusableStates
     */
    public function testAStoredStateThatIsNotUsableIsNoNotice(string $json): void
    {
        self::assertNull(self::notice(new FakeUpdateSettings($json))->current());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function unusableStates(): iterable
    {
        $release = self::release('3.1.0');

        yield 'not JSON' => ['{'];
        yield 'no version' => [(string) json_encode(['download_url' => $release['download_url'], 'release_url' => $release['release_url']])];
        yield 'a version that is not plain' => [(string) json_encode(['version' => '3.1.0-rc1'] + $release)];
        yield 'a download URL elsewhere' => [(string) json_encode(['download_url' => 'https://example.com/x.zip'] + $release)];
        yield 'a release URL elsewhere' => [(string) json_encode(['release_url' => 'https://example.com/'] + $release)];
    }

    public function testTheModuleVersionIsNeededToShowANotice(): void
    {
        $settings = new FakeUpdateSettings();
        self::notice($settings)->record(self::release('3.1.0'), 1763640000);

        self::assertNull(self::notice($settings, '')->current());
    }

    private static function notice(FakeUpdateSettings $settings, string $installed = '3.0.0'): UpdateNotice
    {
        return new UpdateNotice($settings, $installed);
    }

    /**
     * @return array{version: string, download_url: string, release_url: string}
     */
    private static function release(string $version): array
    {
        return [
            'version' => $version,
            'download_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v' . $version . '/smailyforprestashop.zip',
            'release_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/tag/v' . $version,
        ];
    }
}
