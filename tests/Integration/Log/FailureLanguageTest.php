<?php
declare(strict_types=1);

namespace Tests\Integration\Log;

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\LogView;
use PrestaShop\Module\SmailyForPrestaShop\Http\SymfonyTransport;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

require_once __DIR__ . '/LogTestCase.php';

/**
 * A failed Smaily call reads in the admin's language in the Log: the row stores the client's
 * English source text, and the list and Details translate it with its values, so a row written in
 * one language reads right in the other. A row of earlier code reads as a sentence too.
 */
final class FailureLanguageTest extends LogTestCase
{
    /** Each failure kind the client records, and what the Log shows of it in English and Estonian. */
    private const KINDS = [
        'refused credentials' => [
            'Smaily API credentials were rejected',
            'Smaily lükkas API kasutajaandmed tagasi',
        ],
        'no API access in the package' => [
            'Smaily refused the request: the account\'s package does not include API access.',
            'Smaily keeldus päringust: selle konto pakett ei sisalda API-ligipääsu.',
        ],
        'unreachable host' => [
            'Smaily API request failed: could not connect to Smaily',
            'Smaily API päring ebaõnnestus: Smailyga ei saadud ühendust',
        ],
        'timeout' => [
            'Smaily API request failed: no answer within 30 seconds',
            'Smaily API päring ebaõnnestus: Smaily ei vastanud 30 sekundi jooksul',
        ],
        'an error answer' => [
            'Smaily API request failed with HTTP 500',
            'Smaily API päring ebaõnnestus (HTTP 500)',
        ],
        'a refusing code' => [
            'Smaily API returned code 203',
            'Smaily API tagastas koodi 203',
        ],
        'an unexpected answer' => [
            'Smaily API returned a malformed response body',
            'Smaily API tagastas vigase vastuse',
        ],
    ];

    public function testEveryFailureOfASmailyCallReadsInTheAdminsLanguage(): void
    {
        $ids = $this->failEachKind();

        foreach (['en' => 0, 'et' => 1] as $language => $column) {
            $view = new LogView(self::translate($language));
            $rows = array_column(self::rows()->page(new LogFilter(), 0, 50), null, 'id');
            foreach ($ids as $kind => $id) {
                $expected = self::KINDS[$kind][$column];
                self::assertSame($expected, $view->row($rows[$id], false)['error'], $kind . ' in ' . $language);
                $details = $view->details(self::rows()->find('smaily-' . $id), null);
                self::assertSame($expected, $details['error'], $kind . ' in the Details, in ' . $language);
            }
        }
    }

    public function testEveryFailureOfACampaignIntelligenceRowReadsAsASentenceInTheAdminsLanguage(): void
    {
        // What the ingest flusher stores: a code that may pass later alone, a refusal after its class.
        $kinds = [
            'network_error' => [
                'Campaign Intelligence did not answer: the store could not connect to it, or no answer came in time.',
                'Campaign Intelligence ei vastanud: pood ei saanud sellega ühendust või vastus ei tulnud õigel ajal.',
            ],
            'http_503' => ['Campaign Intelligence answered with an error (HTTP 503).', 'Campaign Intelligence vastas veaga (HTTP 503).'],
            'rate_limit_exceeded' => [
                'Campaign Intelligence asked the store to send more slowly for a while.',
                'Campaign Intelligence palus poel mõnda aega aeglasemalt saata.',
            ],
            'internal_error' => ['The Campaign Intelligence request failed (internal_error).', 'Campaign Intelligence\'i päring ebaõnnestus (internal_error).'],
            'permanent_http_400: validation_failed' => [
                'Campaign Intelligence refused the data of this row as invalid.',
                'Campaign Intelligence lükkas selle rea andmed vigastena tagasi.',
            ],
            'permanent_http_404: http_404' => ['Campaign Intelligence answered with an error (HTTP 404).', 'Campaign Intelligence vastas veaga (HTTP 404).'],
            'permanent_http_410: gone_for_good' => ['Campaign Intelligence refused the request (gone_for_good).', 'Campaign Intelligence keeldus päringust (gone_for_good).'],
        ];
        $connection = self::connection(1);
        $ids = [];
        foreach (array_keys($kinds) as $index => $stored) {
            $ids[$stored] = self::ingest(['id_connection' => $connection, 'entity_key' => 'ps-' . $index, 'status' => 'failed', 'attempts' => 5, 'last_error' => $stored]);
        }

        foreach (['en' => 0, 'et' => 1] as $language => $column) {
            $view = new LogView(self::translate($language));
            $rows = array_column(self::rows()->page(new LogFilter(), 0, 50), null, 'id');
            foreach ($ids as $stored => $id) {
                $expected = $kinds[$stored][$column];
                self::assertSame($expected, $view->row($rows[$id], false)['error'], $stored . ' in ' . $language);
                self::assertSame($expected, $view->details(self::rows()->find('intelligence-' . $id), null)['error'], $stored . ' in the Details, in ' . $language);
            }
        }
    }

    public function testASubdomainThatIsNotOnePlainLabelReadsInTheAdminsLanguage(): void
    {
        $queue = new EventQueue();
        $queue->enqueue('contact.sync', 1, ['email' => 'shopper1@example.test'], 'shopper1@example.test', 'en');
        $row = $queue->claim(1)[0];
        try {
            (new Client(new SymfonyTransport(new MockHttpClient([])), 'not a label', 'api-user', 'not-a-real-password'))->post(Client::ENDPOINT_CONTACT, [['email' => 'shopper1@example.test']]);
            self::fail('expected a failure');
        } catch (SmailyClientException $error) {
            $queue->markFailed($row, Failure::of($error));
        }
        $listed = array_column(self::rows()->page(new LogFilter(), 0, 50), null, 'id')[(int) $row['id']];

        self::assertSame('pending', $listed['status'], 'it takes the retry ladder, as Magento keeps it');
        self::assertSame('Alamdomeen peab olema lihtne Smaily alamdomeen, näiteks „demo“: ainult tähed, numbrid ja sidekriipsud.', (new LogView(self::translate('et')))->row($listed, false)['error']);
        self::assertSame('The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.', (new LogView(self::translate('en')))->row($listed, false)['error']);
    }

    public function testTheErrorFilterFindsARowByTheWordsTheEstonianAdminReads(): void
    {
        $ids = $this->failEachKind();
        $translate = self::translate('et');
        $rows = new LogRows(null, static fn (string $term): array => FailureMessage::patternsShowing($term, $translate));
        $found = static fn (string $term): array => array_map('intval', array_column($rows->page(new LogFilter(['last_error' => $term]), 0, 50), 'id'));

        self::assertSame([$ids['unreachable host']], $found('ei saadud ühendust'));
        self::assertEqualsCanonicalizing([$ids['an error answer'], $ids['timeout'], $ids['unreachable host']], $found('päring ebaõnnestus'));
        self::assertSame([$ids['refused credentials']], $found('kasutajaandmed'));
    }

    public function testARowOfEarlierCodeReadsAsASentence(): void
    {
        $id = self::event(['status' => 'pending', 'attempts' => 1, 'last_error' => 'Smaily API request failed: Could not resolve host: demo.sendsmaily.net']);
        $row = array_column(self::rows()->page(new LogFilter(), 0, 50), null, 'id')[$id];

        self::assertSame('Smaily API päring ebaõnnestus: Could not resolve host: demo.sendsmaily.net', (new LogView(self::translate('et')))->row($row, false)['error']);
        self::assertSame('Smaily API request failed: Could not resolve host: demo.sendsmaily.net', (new LogView(self::translate('en')))->row($row, false)['error']);
    }

    /**
     * One queue row per failure kind, each failed by the real client's error as a handler fails it.
     *
     * @return array<string, int> the row of each kind
     */
    private function failEachKind(): array
    {
        $answers = [
            'refused credentials' => new MockResponse('{"code": 0}', ['http_code' => 401]),
            'no API access in the package' => new MockResponse('{"code": 227}', ['http_code' => 403]),
            'unreachable host' => new MockResponse('', ['error' => 'Could not resolve host: demo.sendsmaily.net']),
            'timeout' => new MockResponse((static function (): \Generator {
                yield '';
            })()),
            'an error answer' => new MockResponse('{"message": "Internal Server Error"}', ['http_code' => 500]),
            'a refusing code' => new MockResponse('{"code": 203, "message": "Invalid data"}'),
            'an unexpected answer' => new MockResponse('<html>Bad gateway</html>'),
        ];
        $queue = new EventQueue();
        foreach (array_keys($answers) as $index => $kind) {
            $queue->enqueue('contact.sync', 1, ['email' => 'shopper' . $index . '@example.test'], 'shopper' . $index . '@example.test', 'en');
        }
        $ids = [];
        foreach ($queue->claim(50) as $row) {
            $kind = array_keys($answers)[(int) substr((string) $row['entity_id'], 7, 1)];
            $client = new Client(new SymfonyTransport(new MockHttpClient([$answers[$kind]])), 'demo', 'api-user', 'not-a-real-password');
            try {
                $client->post(Client::ENDPOINT_CONTACT, [['email' => $row['entity_id']]]);
                self::fail($kind . ': expected a failure');
            } catch (SmailyClientException $error) {
                $queue->markFailed($row, Failure::of($error));
            }
            $ids[$kind] = (int) $row['id'];
        }
        self::assertCount(count(self::KINDS), $ids);

        return $ids;
    }

    /**
     * The Log's translate call, over the module's own catalogue in the language given.
     *
     * @return callable(string, array<string, string|int>=): string
     */
    private static function translate(string $language): callable
    {
        $translator = new Translator($language === 'et' ? 'et-EE' : 'en-US');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', dirname(__DIR__, 3) . '/src/translations/et-EE/ModulesSmailyforprestashopAdmin.et-EE.xlf', 'et-EE', BaseController::DOMAIN);

        return static fn (string $id, array $parameters = []): string => $translator->trans($id, $parameters, BaseController::DOMAIN);
    }
}
