<?php
/**
 * A Smaily API mock for `php -S`: the endpoints the module calls, answered as Smaily answers them.
 *
 * State lives in the JSON file that SMAILY_MOCK_STATE names: the API user's credentials, the
 * unsubscribers that the contact list returns, the action log that the history returns, the
 * workflows that the two workflow lists return, and every request received, with the API user it
 * named. SmailyMockServer writes
 * and reads it.
 *
 * - Wrong or missing credentials: HTTP 401.
 * - GET  /api/contact.php?list=2&limit=&offset=  one page of unsubscribers; offset counts pages.
 * - GET  /api/contact.php?email=  the contact's status, as {"email", "is_unsubscribed"}; an address
 *                               the mock does not know answers code 206; while contact reads fail,
 *                               HTTP 500.
 * - GET  /api/history.php?since_seq_id=&limit=&actions=  the action log: the rows whose seq_id is
 *                               above since_seq_id, oldest first, of the comma-separated actions
 *                               only when `actions` is given, at most `limit`.
 * - GET  /api/workflows.php?trigger_type=  the workflows of that trigger type, all without it, as
 *                               Smaily lists them: {"id", "title", "trigger_type", "is_enabled"}.
 * - GET  /api/autoresponder.php?status=  the workflows of that status, all without it.
 * - POST /api/contact.php      one contact or a list; each needs an email, else code 203.
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
    'username' => $_SERVER['PHP_AUTH_USER'] ?? null,
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

if ($method === 'GET' && $path === '/api/contact.php' && isset($_GET['email'])) {
    if (!empty($state['contact_reads_fail'])) {
        $answer(500, ['message' => 'Internal Server Error']);

        return true;
    }
    $email = strtolower((string) $_GET['email']);
    $contacts = array_change_key_case($state['contacts'] ?? [], CASE_LOWER);
    $answer(200, isset($contacts[$email])
        ? ['email' => $email, 'is_unsubscribed' => (string) $contacts[$email]]
        : ['code' => 206, 'message' => 'Email not found']);

    return true;
}

if ($method === 'GET' && $path === '/api/history.php') {
    $since = (int) ($_GET['since_seq_id'] ?? 0);
    $limit = max(1, (int) ($_GET['limit'] ?? 100));
    $actions = isset($_GET['actions']) ? explode(',', (string) $_GET['actions']) : null;
    $rows = array_values(array_filter(
        $state['history'] ?? [],
        static fn (array $row): bool => (int) $row['seq_id'] > $since && ($actions === null || in_array($row['action'], $actions, true))
    ));
    usort($rows, static fn (array $a, array $b): int => (int) $a['seq_id'] <=> (int) $b['seq_id']);
    $answer(200, array_slice($rows, 0, $limit));

    return true;
}

if ($method === 'GET' && $path === '/api/autoresponder.php') {
    $status = $_GET['status'] ?? null;
    $answer(200, array_values(array_filter(
        $state['workflows'] ?? [],
        static fn (array $workflow): bool => $status === null || ($workflow['status'] ?? null) === $status
    )));

    return true;
}

if ($method === 'GET' && $path === '/api/workflows.php') {
    $type = $_GET['trigger_type'] ?? null;
    $answer(200, array_values(array_filter(
        $state['workflows'] ?? [],
        static fn (array $workflow): bool => $type === null || ($workflow['trigger_type'] ?? null) === $type
    )));

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
