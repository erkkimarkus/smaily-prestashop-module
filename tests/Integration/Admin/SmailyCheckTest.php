<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SmailyCheck;
use PrestaShop\Module\SmailyForPrestaShop\Http\SymfonyTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;
use Tests\Integration\IntegrationTestCase;

/**
 * "Test connection" asks Smaily with Magento's call, words each answer as Magento's TestSmaily
 * does, and its answer never carries the password. Needs the shop's translator, so it runs in the
 * shop.
 */
final class SmailyCheckTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{MockResponse|null, bool, ?string}>
     */
    public function answers(): array
    {
        return [
            'accepted' => [new MockResponse('[]', ['http_code' => 200]), true, null],
            'wrong credentials' => [new MockResponse('{"message":"Unauthorized"}', ['http_code' => 401]), false, 'Smaily rejected these credentials. Check the subdomain, username and password.'],
            'a package without API access' => [new MockResponse('{"code":227}', ['http_code' => 403]), false, 'Smaily refused the request because this account\'s package does not include API access. Upgrade the package in Smaily to connect — until then the credentials cannot be checked at all.'],
            'an unknown subdomain' => [new MockResponse('', ['http_code' => 404]), false, 'Smaily does not know this subdomain. Check the subdomain.'],
            'Smaily down' => [new MockResponse('', ['http_code' => 503]), false, 'Could not reach Smaily: Smaily API request failed with HTTP 503'],
            'no network' => [new MockResponse('', ['error' => 'Could not resolve host']), false, 'Could not reach Smaily: Smaily API request failed: could not connect to Smaily'],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function testWordsEachAnswer(MockResponse $response, bool $connected, ?string $error): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, &$requests): MockResponse {
            $requests[] = [$method, $url];

            return $response;
        });
        $check = new SmailyCheck(new SymfonyTransport($client), \Context::getContext()->getTranslator());

        $answer = $check->check('demo', 'api-user', 'a-secret-password');

        self::assertSame(['connected' => $connected, 'error' => $error], $answer);
        self::assertSame([['GET', 'https://demo.sendsmaily.net/api/workflows.php?trigger_type=form_submitted']], $requests, 'Magento\'s validateCredentials() call');
        self::assertStringNotContainsString('a-secret-password', (string) json_encode($answer));
    }

    public function testAskNothingForASubdomainThatIsNotPlain(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('no request');
        });
        $check = new SmailyCheck(new SymfonyTransport($client), \Context::getContext()->getTranslator());

        self::assertSame(
            ['connected' => false, 'error' => 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.'],
            $check->check('evil.example.com/x', 'api-user', 'a-secret-password')
        );
    }

    public function testSaysWhySmailyCouldNotBeReachedInTheAdminsLanguage(): void
    {
        $translator = new Translator('et-EE');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', dirname(__DIR__, 3) . '/src/translations/et-EE/ModulesSmailyforprestashopAdmin.et-EE.xlf', 'et-EE', BaseController::DOMAIN);
        $answers = [
            'Smailyga ei saadud ühendust: Smaily API päring ebaõnnestus (HTTP 503)' => new MockResponse('', ['http_code' => 503]),
            'Smailyga ei saadud ühendust: Smaily API päring ebaõnnestus: Smailyga ei saadud ühendust' => new MockResponse('', ['error' => 'Could not resolve host']),
        ];

        foreach ($answers as $said => $response) {
            $check = new SmailyCheck(new SymfonyTransport(new MockHttpClient([$response])), $translator);

            self::assertSame(['connected' => false, 'error' => $said], $check->check('demo', 'api-user', 'a-secret-password'));
        }
    }
}
