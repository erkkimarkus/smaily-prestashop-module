<?php
declare(strict_types=1);

namespace Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;

/**
 * The latest release of the official repository: one GET that carries no store data, and an answer
 * that is taken only when every URL in use points to the official repository.
 */
final class LatestReleaseTest extends TestCase
{
    private const RELEASE_PAGE = 'https://github.com/sendsmaily/smaily-prestashop-module/releases/tag/v3.1.0';

    private const ZIP = 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v3.1.0/smailyforprestashop.zip';

    public function testSendsOneGetWithAFixedUserAgentAndNothingElse(): void
    {
        $transport = new RecordingTransport(new Response(200, (string) json_encode(self::release())));

        (new LatestRelease($transport, '3.0.0'))->fetch();

        self::assertSame([[
            'GET',
            'https://api.github.com/repos/sendsmaily/smaily-prestashop-module/releases/latest',
            [
                'headers' => [
                    'User-Agent' => 'SmailyForPrestaShop/3.0.0',
                    'Accept' => 'application/vnd.github+json',
                ],
                'timeout' => LatestRelease::TIMEOUT_SECONDS,
                'max_duration' => LatestRelease::TIMEOUT_SECONDS,
            ],
        ]], $transport->requests);
    }

    public function testAsksTheGivenApiBase(): void
    {
        $transport = new RecordingTransport(new Response(200, (string) json_encode(self::release())));

        (new LatestRelease($transport, '3.0.0', 'http://127.0.0.1:9999'))->fetch();

        self::assertSame('http://127.0.0.1:9999/repos/sendsmaily/smaily-prestashop-module/releases/latest', $transport->requests[0][1]);
    }

    public function testReadsTheVersionTheReleasePageAndTheZipAsset(): void
    {
        self::assertSame(
            ['version' => '3.1.0', 'download_url' => self::ZIP, 'release_url' => self::RELEASE_PAGE],
            LatestRelease::parse((string) json_encode(self::release()))
        );
    }

    public function testDownloadsFromTheReleasePageWhenNoZipIsAttached(): void
    {
        $release = self::release(['assets' => [['name' => 'notes.txt', 'browser_download_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v3.1.0/notes.txt']]]);

        self::assertSame(self::RELEASE_PAGE, LatestRelease::parse((string) json_encode($release))['download_url']);
    }

    public function testTakesTheFirstZipAsset(): void
    {
        $other = 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v3.1.0/other.ZIP';
        $release = self::release(['assets' => [
            ['name' => 'notes.txt', 'browser_download_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v3.1.0/notes.txt'],
            ['name' => 'other.ZIP', 'browser_download_url' => $other],
            ['name' => 'smailyforprestashop.zip', 'browser_download_url' => self::ZIP],
        ]]);

        self::assertSame($other, LatestRelease::parse((string) json_encode($release))['download_url']);
    }

    /**
     * @dataProvider unusableAnswers
     */
    public function testRejectsAnUnusableAnswerWhole(string $body): void
    {
        $this->expectException(\UnexpectedValueException::class);

        LatestRelease::parse($body);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function unusableAnswers(): iterable
    {
        $json = static fn (array $release): string => (string) json_encode($release);

        yield 'not JSON' => ['<html>'];
        yield 'a JSON list' => ['[]'];
        yield 'no tag' => [$json(self::release(['tag_name' => null]))];
        yield 'a tag that is no plain version' => [$json(self::release(['tag_name' => 'v3.1.0-rc1']))];
        yield 'a number as tag' => [$json(self::release(['tag_name' => 3]))];
        yield 'a draft' => [$json(self::release(['draft' => true]))];
        yield 'a pre-release' => [$json(self::release(['prerelease' => true]))];
        yield 'no draft field' => [$json(self::release(['draft' => null]))];
        yield 'no release page' => [$json(self::release(['html_url' => null]))];
        yield 'assets not a list' => [$json(self::release(['assets' => 'x']))];
        yield 'a release page on another host' => [$json(self::release(['html_url' => 'https://example.com/sendsmaily/smaily-prestashop-module/releases/tag/v3.1.0']))];
        yield 'a release page of another repository' => [$json(self::release(['html_url' => 'https://github.com/someone/smaily-prestashop-module/releases/tag/v3.1.0']))];
        yield 'a zip on another host' => [$json(self::release(['assets' => [['name' => 'smailyforprestashop.zip', 'browser_download_url' => 'https://example.com/smailyforprestashop.zip']]]))];
        yield 'a zip of another repository' => [$json(self::release(['assets' => [['name' => 'smailyforprestashop.zip', 'browser_download_url' => 'https://github.com/sendsmaily/other/releases/download/v3.1.0/smailyforprestashop.zip']]]))];
    }

    /**
     * @dataProvider urls
     */
    public function testAcceptsOnlyUrlsOfTheOfficialRepository(string $url, bool $official): void
    {
        self::assertSame($official, LatestRelease::isOfficialUrl($url));
    }

    /**
     * @return iterable<array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'the release page' => [self::RELEASE_PAGE, true];
        yield 'the zip' => [self::ZIP, true];
        yield 'the API' => ['https://api.github.com/repos/sendsmaily/smaily-prestashop-module/releases/1', true];
        yield 'plain http' => ['http://github.com/sendsmaily/smaily-prestashop-module/releases/tag/v3.1.0', false];
        yield 'another host' => ['https://evil.example/sendsmaily/smaily-prestashop-module/', false];
        yield 'a host that ends like GitHub' => ['https://github.com.evil.example/sendsmaily/smaily-prestashop-module/', false];
        yield 'credentials before the host' => ['https://github.com@evil.example/sendsmaily/smaily-prestashop-module/', false];
        yield 'a port' => ['https://github.com:8443/sendsmaily/smaily-prestashop-module/', false];
        yield 'another owner' => ['https://github.com/someone/smaily-prestashop-module/releases', false];
        yield 'a repository with a longer name' => ['https://github.com/sendsmaily/smaily-prestashop-module-fork/releases', false];
        yield 'the repository root without a path' => ['https://github.com/sendsmaily/smaily-prestashop-module', false];
        yield 'a path that climbs out' => ['https://github.com/sendsmaily/smaily-prestashop-module/../../someone/x', false];
        yield 'a query' => ['https://github.com/sendsmaily/smaily-prestashop-module/releases?to=https://evil.example', false];
        yield 'a fragment' => ['https://github.com/sendsmaily/smaily-prestashop-module/releases#x', false];
        yield 'a backslash' => ['https://github.com/sendsmaily/smaily-prestashop-module/\\evil.example', false];
        yield 'a repository path on the API host without repos' => ['https://api.github.com/sendsmaily/smaily-prestashop-module/releases', false];
    }

    /**
     * @dataProvider failedStatuses
     */
    public function testAnAnswerOtherThan200IsAFailure(int $status): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new LatestRelease(new RecordingTransport(new Response($status, (string) json_encode(self::release()))), '3.0.0'))->fetch();
    }

    /**
     * @return iterable<array{int}>
     */
    public static function failedStatuses(): iterable
    {
        yield 'rate limit' => [403];
        yield 'no release' => [404];
        yield 'too many requests' => [429];
        yield 'server error' => [500];
        yield 'a redirect' => [301];
    }

    public function testANetworkFailurePassesOn(): void
    {
        $transport = new RecordingTransport(new NetworkException('timeout'));

        $this->expectException(NetworkException::class);

        (new LatestRelease($transport, '3.0.0'))->fetch();
    }

    /**
     * A release as GitHub answers it, with $changes applied; a null value removes the field.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function release(array $changes = []): array
    {
        $release = array_merge([
            'url' => 'https://api.github.com/repos/sendsmaily/smaily-prestashop-module/releases/1',
            'html_url' => self::RELEASE_PAGE,
            'id' => 1,
            'tag_name' => 'v3.1.0',
            'name' => '3.1.0',
            'draft' => false,
            'prerelease' => false,
            'assets' => [['name' => 'smailyforprestashop.zip', 'browser_download_url' => self::ZIP]],
            'body' => 'Notes',
        ], $changes);

        return array_filter($release, static fn ($value): bool => $value !== null);
    }
}

/**
 * Records each request and answers every one with the same response or failure.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $requests = [];

    /** @var Response|\Throwable */
    private $answer;

    /**
     * @param Response|\Throwable $answer
     */
    public function __construct($answer)
    {
        $this->answer = $answer;
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = [$method, $url, $options];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
