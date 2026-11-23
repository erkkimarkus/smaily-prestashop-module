<?php
/**
 * A mock of Smaily's subscription service for `php -S`: the receiver of the installation report,
 * `POST /v1/reports`, answered as docs/contracts/installation-report-v1.md v1.2.0 states it. A dev
 * shop behind the egress guard sends its reports here (docs/DEV_ENVIRONMENT.md 4a); nothing ever
 * reaches the real `subscription.smaily.com`.
 *
 * It checks a report in the contract's order: the body is JSON ("Error codes": 400
 * `invalid_json`), it is at most 64 KiB (413 `body_too_large`), it follows the body's rules
 * (contract.php: 400 `validation_failed` with `details.errors`, or 400 `unknown_product`), and
 * only then the credentials (401 `invalid_smaily_credentials`). The real service asks Smaily about
 * the credentials; the mock compares them with the ones the Smaily mock accepts (the state file
 * that SMAILY_MOCK_STATE names, `credentials`), and accepts any Basic credentials when that file
 * names none. An accepted report answers 200 with an `installation_id` that is the same for the
 * same `product` and `installation_url`.
 *
 * State lives in the JSON file that SUBSCRIPTION_MOCK_STATE names:
 *
 *   fail      {status, error?, retry_after?}  every report that passed the checks gets this HTTP
 *             status instead of 200 (429, 500, 503, a 308 redirect…), with `Retry-After` when
 *             `retry_after` is set
 *   delay     seconds to wait before any answer (the module gives up after 5)
 *   requests  every report received: the time, the answered status, the decoded body, the
 *             contract fields it broke (`errors`), the fields the contract does not name
 *             (`unknown_fields`) and whether its credentials were the accepted ones
 *             (`authenticated`). Never the credentials themselves.
 *
 * Anything but `POST /v1/reports` answers 404, and another method on that path 405.
 */
declare(strict_types=1);

require __DIR__ . '/contract.php';

$stateFile = (string) getenv('SUBSCRIPTION_MOCK_STATE');
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
$state = is_array($state) ? $state : [];

$answer = static function (int $status, array $body, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($body);
};
$error = static fn (string $code, string $message): array => ['error' => $code, 'message' => $message];

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/v1/reports') {
    $answer(404, $error('not_found', 'No such path.'));

    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $answer(405, $error('method_not_allowed', 'Send a report with POST.'), ['Allow' => 'POST']);

    return;
}
if (!empty($state['delay'])) {
    usleep((int) ((float) $state['delay'] * 1000000));
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$record = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'status' => 0, 'body' => is_array($body) ? $body : null, 'errors' => [], 'unknown_fields' => [], 'authenticated' => false];
$finish = static function (int $status, array $payload, array $headers = []) use (&$record, &$state, $stateFile, $answer): void {
    $record['status'] = $status;
    // Another request may have written the state while this one waited.
    $stored = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
    $stored = is_array($stored) ? $stored : $state;
    $stored['requests'][] = $record;
    file_put_contents($stateFile, json_encode($stored), LOCK_EX);
    $answer($status, $payload, $headers);
};

if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0 || !is_array($body)) {
    $finish(400, $error('invalid_json', 'The body is not JSON.'));

    return;
}
if (strlen($raw) > 65536) {
    $finish(413, $error('body_too_large', 'The body is larger than 64 KiB.'));

    return;
}
$record['unknown_fields'] = installation_report_unknown_fields($body);
$record['errors'] = installation_report_errors($body);
if ($record['errors'] !== []) {
    if ($record['errors'][0]['code'] === 'unknown_product') {
        $finish(400, $error('unknown_product', 'The product is not in the registry.'));

        return;
    }
    $finish(400, $error('validation_failed', 'One or more fields failed validation.') + ['details' => ['errors' => $record['errors']]]);

    return;
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$accepted = null;
$smailyState = (string) getenv('SMAILY_MOCK_STATE');
if ($smailyState !== '' && is_file($smailyState)) {
    $credentials = json_decode((string) file_get_contents($smailyState), true)['credentials'] ?? null;
    if (is_array($credentials) && isset($credentials['username'], $credentials['password'])) {
        $accepted = 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password']);
    }
}
$record['authenticated'] = preg_match('/^Basic \S+$/D', $authorization) === 1 && ($accepted === null || hash_equals($accepted, $authorization));
if (!$record['authenticated']) {
    $finish(401, $error('invalid_smaily_credentials', 'Smaily refused the credentials.'));

    return;
}

if (is_array($state['fail'] ?? null) && !empty($state['fail']['status'])) {
    $status = (int) $state['fail']['status'];
    $headers = isset($state['fail']['retry_after']) ? ['Retry-After' => (string) (int) $state['fail']['retry_after']] : [];
    if ($status >= 300 && $status < 400) {
        $headers['Location'] = 'https://subscription.smaily.com/moved';
    }
    $finish($status, $error((string) ($state['fail']['error'] ?? 'unavailable'), 'The mock was told to fail.'), $headers);

    return;
}

$hash = md5($body['product'] . "\n" . rtrim(strtolower($body['installation_url']), '/'));
$finish(200, ['installation_id' => sprintf(
    '%s-%s-4%s-8%s-%s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    substr($hash, 13, 3),
    substr($hash, 17, 3),
    substr($hash, 20, 12)
)]);
