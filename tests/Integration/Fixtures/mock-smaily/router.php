<?php
/**
 * A Smaily API mock for `php -S`: the endpoints the module calls, answered as Smaily answers them.
 *
 * State lives in the JSON file that SMAILY_MOCK_STATE names: the API user's credentials, the
 * unsubscribers that the contact list returns, and every request received. SmailyMockServer writes
 * and reads it.
 *
 * - Wrong or missing credentials: HTTP 401.
 * - GET  /api/contact.php?list=2&limit=&offset=  one page of unsubscribers; offset counts pages.
 * - POST /api/contact.php       one contact or a list; each needs an email, else code 203.
 * - POST /api/autoresponder.php `addresses`, each with an email, else code 203; `autoresponder` is
 *                               optional (without it Smaily starts its "opted in" workflows).
 * - Anything else: HTTP 404.
 */
declare(strict_types=1);

$stateFile = (string) getenv('SMAILY_MOCK_STATE');
$state = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : [];

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);

$credentials = $state['credentials'] ?? ['username' => '', 'password' => ''];
$authorized = ($_SERVER['PHP_AUTH_USER'] ?? null) === $credentials['username']
    && ($_SERVER['PHP_AUTH_PW'] ?? null) === $credentials['password'];

$state['requests'][] = [
    'method' => $method,
    'path' => $path,
    'query' => $_GET,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => $body,
    'authorized' => $authorized,
];
file_put_contents($stateFile, json_encode($state));

$answer = static function (int $status, $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
};
$hasEmails = static function ($rows): bool {
    if (!is_array($rows) || $rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['email'])) {
            return false;
        }
    }

    return true;
};

if (!$authorized) {
    $answer(401, ['message' => 'Unauthorized']);

    return true;
}

if ($method === 'GET' && $path === '/api/contact.php' && ($_GET['list'] ?? null) === '2') {
    $limit = max(1, (int) ($_GET['limit'] ?? 100));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $rows = array_map(
        static fn (string $email): array => ['email' => $email, 'is_unsubscribed' => '1'],
        array_slice($state['unsubscribers'] ?? [], $offset * $limit, $limit)
    );
    $answer(200, $rows);

    return true;
}

if ($method === 'POST' && $path === '/api/contact.php') {
    $contacts = is_array($body) && isset($body['email']) ? [$body] : $body;
    $answer(200, $hasEmails($contacts) ? ['code' => 101, 'message' => 'OK'] : ['code' => 203, 'message' => 'Invalid data']);

    return true;
}

if ($method === 'POST' && $path === '/api/autoresponder.php') {
    $valid = is_array($body) && $hasEmails($body['addresses'] ?? null);
    $answer(200, $valid ? ['code' => 101, 'message' => 'OK'] : ['code' => 203, 'message' => 'Invalid data']);

    return true;
}

$answer(404, ['message' => 'Not found']);

return true;
