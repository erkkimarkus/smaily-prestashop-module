<?php
/**
 * A mock of the Campaign Intelligence engine for `php -S`: the three calls of the connection,
 * answered as docs/RECENGINE_API_CONTRACT.md v1.36.0 states them. Each answer below names the
 * contract section its shape comes from; the shapes were taken from the contract text, not from
 * another connector's mock.
 *
 * State lives in the JSON file that ENGINE_MOCK_STATE names. EngineMockServer writes and reads it;
 * in a dev shop the egress guard seeds it (docs/DEV_ENVIRONMENT.md 4a). It holds what the engine
 * would hold (the connections by API key, the used setup tokens, the prefixes the store assigned),
 * every request received, and the switches a test sets:
 *
 *   smaily_subdomain    string|null  the Smaily account the exchange names (§1; default null)
 *   tenant_id           string       the engine store the next exchange joins (default: a new one)
 *   next_prefix         string       the prefix the next exchange assigns to a connector that
 *                                    declares `connection_prefix` (default "", as the engine
 *                                    assigns today, §1 "Status")
 *   prefix_answer       {exchange|ping: mixed}  a raw `config.connection.prefix` of any shape
 *   omit_connection     {exchange|ping: bool}   an answer without `config.connection` (an engine
 *                                    before v1.33.0)
 *   require_capability  bool         the exchange refuses a connector without the capability
 *                                    (§1 "Response 409"; off at the engine until announced)
 *   tenant_inactive     bool         every authenticated call but §16 answers 403 (§2)
 *   fail                {exchange|ping|uninstall: {status, error?, message?}}  the route answers
 *                                    this HTTP status ("Error handling"); 401 and 409 take the
 *                                    contract's own bodies
 *   delay               {exchange|ping|uninstall: seconds}  the route waits before it answers
 *
 * Routes:
 * - POST /api/setup/exchange       §1. A token is used once (410 afterwards); a token that starts
 *                                  with "notfound" answers 404.
 * - GET  /api/v1/ingest/ping       §2.
 * - POST /api/v1/store/uninstall   §16. Revokes the calling key.
 * - Anything else: HTTP 404.
 *
 * An authenticated call needs a key that an exchange of this mock issued and that is not revoked
 * ("Authentication"): any other answers 401 `unauthorised`.
 */
declare(strict_types=1);

const HISTORY = ['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first'];

$stateFile = (string) getenv('ENGINE_MOCK_STATE');
$read = static function () use ($stateFile): array {
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];

    return is_array($state) ? $state : [];
};
$write = static function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$key = preg_match('/^Bearer (\S+)$/', $authorization, $match) === 1 ? $match[1] : null;
$routes = [
    'POST /api/setup/exchange' => 'exchange',
    'GET /api/v1/ingest/ping' => 'ping',
    'POST /api/v1/store/uninstall' => 'uninstall',
];
$route = $routes[$method . ' ' . $path] ?? null;

$state = $read();
$state['requests'][] = [
    'method' => $method,
    'path' => $path,
    'query' => $_GET,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'authorization' => $authorization === '' ? null : $authorization,
    'raw' => $raw,
    'body' => $body,
];
$write($state);

// "Base context": every answer is JSON, and an answer to a request that carries a key is not
// cached. "Versioning": every answer names the engine version.
header('Content-Type: application/json; charset=utf-8');
header('X-Engine-Version: 1.0.0');
if ($key !== null) {
    header('Cache-Control: no-store');
}
$answer = static function (int $status, array $payload): bool {
    http_response_code($status);
    echo json_encode($payload);

    return true;
};
$now = gmdate('Y-m-d\TH:i:s\Z');

if ($route === null) {
    return $answer(404, ['error' => 'not_found', 'message' => 'No such route.', 'timestamp' => $now]);
}

$delay = (float) ($state['delay'][$route] ?? 0);
if ($delay > 0) {
    usleep((int) ($delay * 1000000));
    // Another request may have changed the state meanwhile.
    $state = $read();
}

// "Authentication": one answer for a missing header, an unknown key and a revoked key.
$unauthorised = ['error' => 'unauthorised', 'message' => 'Valid API key required (Authorization: Bearer <api_key>)'];
// §1 "Response 409".
$updateRequired = [
    'error' => 'plugin_update_required',
    'message' => 'Update the plugin: this engine needs a plugin version that applies the connection prefix.',
    'request_id' => 'req_' . bin2hex(random_bytes(8)),
];

$failure = $state['fail'][$route] ?? null;
if (is_array($failure)) {
    $status = (int) $failure['status'];
    if ($status === 401 && !isset($failure['error'])) {
        return $answer(401, $unauthorised);
    }
    if ($status === 409 && !isset($failure['error'])) {
        return $answer(409, $updateRequired);
    }

    // "Error response format".
    return $answer($status, [
        'error' => $failure['error'] ?? 'internal_error',
        'message' => $failure['message'] ?? 'The mock engine was told to fail.',
        'timestamp' => $now,
    ]);
}

$base = (string) ($state['base_url'] ?? 'https://intelligence.smaily.com');
$connections = is_array($state['connections'] ?? null) ? $state['connections'] : [];

if ($route === 'exchange') {
    $token = is_array($body) && is_string($body['setup_token'] ?? null) ? $body['setup_token'] : '';
    if ($token === '') {
        return $answer(400, [
            'error' => 'validation_failed',
            'message' => 'One or more fields failed validation',
            'request_id' => 'req_' . bin2hex(random_bytes(8)),
            'timestamp' => $now,
        ]);
    }
    if (strpos($token, 'notfound') === 0) {
        // §1 "Response 404 Not Found".
        return $answer(404, [
            'error' => 'setup_token_not_found',
            'message' => 'Setup token not found. Verify the URL is correct.',
            'request_id' => 'req_' . bin2hex(random_bytes(8)),
        ]);
    }
    if (in_array($token, $state['used_tokens'] ?? [], true)) {
        // §1 "Response 410 Gone".
        return $answer(410, [
            'error' => 'setup_token_expired_or_used',
            'message' => 'This setup token has expired or has already been used. Ask the engine administrator to generate a new one.',
            'regenerate_url' => $base . '/admin/tenants/{tenant_id}#setup',
            'request_id' => 'req_' . bin2hex(random_bytes(8)),
        ]);
    }
    $capabilities = is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [];
    $declares = in_array('connection_prefix', $capabilities, true);
    if (!$declares && !empty($state['require_capability'])) {
        // The token is not used: the same link works after the connector's update.
        return $answer(409, $updateRequired);
    }

    // §1 "Connection prefix": only a connector that declares the capability gets one; a
    // `previous_prefix` that the store assigned is reused, else a new one is assigned.
    $assigned = is_array($state['assigned_prefixes'] ?? null) ? $state['assigned_prefixes'] : [];
    $previous = is_array($body['connection'] ?? null) ? ($body['connection']['previous_prefix'] ?? null) : null;
    $prefix = '';
    if ($declares) {
        $prefix = is_string($previous) && in_array($previous, $assigned, true)
            ? $previous
            : (string) ($state['next_prefix'] ?? '');
    }
    if ($prefix !== '' && !in_array($prefix, $assigned, true)) {
        $assigned[] = $prefix;
    }

    $apiKey = 'sk_' . bin2hex(random_bytes(16));
    $tenantId = (string) ($state['tenant_id'] ?? sprintf(
        '%s-%s-4%s-8%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        substr(bin2hex(random_bytes(2)), 1),
        substr(bin2hex(random_bytes(2)), 1),
        bin2hex(random_bytes(6))
    ));
    $connections[$apiKey] = [
        'tenant_id' => $tenantId,
        'prefix' => $prefix,
        'revoked' => false,
        'site_url' => is_array($body['plugin_info'] ?? null) ? ($body['plugin_info']['site_url'] ?? null) : null,
    ];
    $state['connections'] = $connections;
    $state['assigned_prefixes'] = $assigned;
    $state['used_tokens'][] = $token;
    $write($state);

    // §1 "Response 200 OK": the config block as the contract prints it.
    $config = [
        'tracking_cookie_name' => 'smaily_rec_uid',
        'session_cookie_name' => 'smaily_anon_sid',
        'rec_id_cookie_name' => 'smaily_rec_id',
        'context_cookie_name' => 'smaily_rec_ctx',
        'cookie_ttl_days' => 365,
        'session_ttl_days' => 30,
        'rec_id_ttl_days' => 30,
        'context_ttl_days' => 30,
        'rate_limit_browse' => 500,
        'rate_limit_other' => 100,
        'batch_size_max' => 100,
        'supported_languages' => ['et', 'en'],
        'url_param_visitor_token' => 'smaily_vt',
        'url_param_rec_id' => 'smaily_rec',
        'url_param_context' => 'smaily_ctx',
        'history' => HISTORY,
        'connection' => ['prefix' => $prefix],
    ];
    if (is_array($state['prefix_answer'] ?? null) && array_key_exists('exchange', $state['prefix_answer'])) {
        $config['connection'] = ['prefix' => $state['prefix_answer']['exchange']];
    }
    if (!empty($state['omit_connection']['exchange'])) {
        unset($config['connection']);
    }

    return $answer(200, [
        'tenant_id' => $tenantId,
        'tenant_name' => 'Mock Store',
        'smaily_subdomain' => $state['smaily_subdomain'] ?? null,
        'api_key' => $apiKey,
        'engine_base_url' => $base,
        'engine_version' => '1.0.0',
        // §1: the map as the contract prints it. `automations_test_send`, `automations_go_live`
        // and `console_entry` are absent, as the engine returns them only once they are live;
        // `ingest_stock_alerts` is returned since 2026-11-20. `{email}`, `{key}` and
        // `{email_key}` are the engine's own placeholders.
        'endpoints' => [
            'ingest_ping' => $base . '/api/v1/ingest/ping',
            'ingest_catalog' => $base . '/api/v1/ingest/catalog',
            'ingest_catalog_remove' => $base . '/api/v1/ingest/catalog/remove',
            'ingest_catalog_manifest' => $base . '/api/v1/ingest/catalog/manifest',
            'ingest_customers' => $base . '/api/v1/ingest/customers',
            'ingest_orders' => $base . '/api/v1/ingest/orders',
            'ingest_browse' => $base . '/api/v1/ingest/browse',
            'ingest_history_progress' => $base . '/api/v1/ingest/history/progress',
            'ingest_stock_alerts' => $base . '/api/v1/ingest/stock-alerts',
            'identity_merge' => $base . '/api/v1/identity/merge',
            'customer_export' => $base . '/api/v1/customer/{email}/export',
            'customer_delete' => $base . '/api/v1/customer/{email}',
            'customer_opt_out' => $base . '/api/v1/customer/{email}/opt-out',
            'recommendations_preview' => $base . '/api/v1/recommendations/preview',
            'recommendations_issue' => $base . '/api/v1/recommendations/issue',
            'recommendations_customer' => $base . '/api/v1/recommendations/customer',
            'automations_catalog' => $base . '/api/v1/automations/catalog',
            'automations_config' => $base . '/api/v1/automations/config',
            'notifications_ingest' => $base . '/api/v1/notifications/ingest',
            'store_uninstall' => $base . '/api/v1/store/uninstall',
            'automations_setup_link' => $base . '/api/v1/automations/{key}/setup-link',
            'store_branding' => $base . '/api/v1/store/branding',
            'emails_design_link' => $base . '/api/v1/emails/{email_key}/design-link',
            'emails_designs' => $base . '/api/v1/emails/designs',
        ],
        'config' => $config,
        'issued_at' => $now,
    ]);
}

$connection = $key !== null ? ($connections[$key] ?? null) : null;

if ($route === 'uninstall') {
    // §16 "Errors": 401 for an unknown key and for a key revoked other than by this endpoint. A
    // repeat with the key that this endpoint revoked answers the store's current state. A
    // suspended store may still disconnect: no 403 here.
    if ($connection === null || !in_array($connection['revoked'], [false, 'uninstall'], true)) {
        return $answer(401, $unauthorised);
    }
    $connections[$key]['revoked'] = 'uninstall';
    $state['connections'] = $connections;
    $write($state);

    $others = false;
    foreach ($connections as $other) {
        $others = $others || ($other['revoked'] === false && $other['tenant_id'] === $connection['tenant_id']);
    }

    // §16 "Response 200 OK", both forms.
    return $answer(200, [
        'ok' => true,
        'status' => $others ? 'connection_removed' : 'store_paused',
        'other_connections_remain' => $others,
    ]);
}

// §2.
if ($connection === null || $connection['revoked'] !== false) {
    return $answer(401, $unauthorised);
}
if (!empty($state['tenant_inactive'])) {
    // §2 "Response 403": the body is exact; `tenant_status` is a fixed string.
    return $answer(403, [
        'error' => 'tenant_inactive',
        'message' => 'This tenant is currently deactivated. Contact engine administrator.',
        'tenant_status' => 'suspended',
    ]);
}
$config = ['history' => HISTORY, 'connection' => ['prefix' => $connection['prefix']]];
if (is_array($state['prefix_answer'] ?? null) && array_key_exists('ping', $state['prefix_answer'])) {
    $config['connection'] = ['prefix' => $state['prefix_answer']['ping']];
}
if (!empty($state['omit_connection']['ping'])) {
    unset($config['connection']);
}

// §2 "Response 200 OK".
return $answer(200, [
    'ok' => true,
    'pong' => true,
    'tenant_id' => $connection['tenant_id'],
    'industry' => 'pet',
    'engine_version' => '1.0.0',
    'ts' => $now,
    'config' => $config,
]);
