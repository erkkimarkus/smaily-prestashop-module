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
 * The storefront routes (§6, §15) take `browse` and `recommendations` as route names in `fail` and
 * `delay`, and read two more switches:
 *
 *   recommendations     {"customer:<id>"|"token:<visitor token>": list of slots}  the shopper's
 *                                    recommendations (§15). A slot gives `external_id` and may give
 *                                    any other field of a §15 slot; the mock fills the rest.
 *                                    A shopper without an entry gets the empty answer.
 *   visitor_tokens      {"<visitor token>": customer id}  the tokens that are bound to a
 *                                    customer: a browse event with one counts as matched (§6)
 *
 * Routes:
 * - POST /api/setup/exchange       §1. A token is used once (410 afterwards); a token that starts
 *                                  with "notfound" answers 404.
 * - GET  /api/v1/ingest/ping       §2.
 * - POST /api/v1/store/uninstall   §16. Revokes the calling key.
 * - GET    /api/v1/customer/{email}/export   §8. Privacy: what the engine holds of a shopper.
 * - DELETE /api/v1/customer/{email}          §9. Privacy: deletes the shopper; a repeat answers 404.
 * - POST   /api/v1/customer/{email}/opt-out  §10. Privacy: the shopper's choice about profiling.
 *   The three know every address, but one that `unknown_customers` lists or that §9 deleted
 *   (`deleted_customers`): that one answers 404. `opt_outs` holds each address's choice. Their
 *   names for `fail` and `delay` are `customer_export`, `customer_delete` and `customer_opt_out`.
 * - POST /api/v1/ingest/browse     §6. Validates each event on its own; an `event_id` counts once.
 * - POST /api/v1/recommendations/customer  §15.
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
    'POST /api/v1/ingest/browse' => 'browse',
    'POST /api/v1/recommendations/customer' => 'recommendations',
];
$route = $routes[$method . ' ' . $path] ?? null;

// Privacy (§8, §9, §10): the address stands in the path, URL-encoded.
$customerRoutes = ['GET /export' => 'customer_export', 'DELETE ' => 'customer_delete', 'POST /opt-out' => 'customer_opt_out'];
$customerEmail = null;
if ($route === null && preg_match('~^/api/v1/customer/([^/]+)(/export|/opt-out)?$~', $path, $customerMatch) === 1) {
    $route = $customerRoutes[$method . ' ' . ($customerMatch[2] ?? '')] ?? null;
    $customerEmail = strtolower(rawurldecode($customerMatch[1]));
}

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

// The ingest routes (§3, §3b, §3c, §4, §5, §7) answer in ingest.php, which lists them and their
// switches.
if ((require __DIR__ . '/ingest.php') === true) {
    return true;
}

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

// Privacy (§8, §9, §10).
if ($customerEmail !== null) {
    if ($connection === null || $connection['revoked'] !== false) {
        return $answer(401, $unauthorised);
    }
    if (!empty($state['tenant_inactive'])) {
        return $answer(403, [
            'error' => 'tenant_inactive',
            'message' => 'This tenant is currently deactivated. Contact engine administrator.',
            'tenant_status' => 'suspended',
        ]);
    }
    $unknown = in_array($customerEmail, $state['unknown_customers'] ?? [], true)
        || in_array($customerEmail, $state['deleted_customers'] ?? [], true);
    if ($unknown) {
        // §8, §9 and §10 "Response 404 Not Found". The message names the address, as an engine's
        // text can: no caller may keep or show it.
        return $answer(404, [
            'error' => 'customer_not_found',
            'message' => 'No customer with the email ' . $customerEmail . '.',
            'timestamp' => $now,
        ]);
    }
    $optOut = $state['opt_outs'][$customerEmail] ?? ['opt_out_status' => false, 'effective_at' => null];

    if ($route === 'customer_export') {
        // §8 "Response 200 OK": the body's keys as the contract prints them, with one order.
        $customerId = '660f9500-0000-4000-8000-' . substr(md5($customerEmail), 0, 12);
        $orderId = '82f231e1-0000-4000-8000-' . substr(md5($customerEmail), 12, 12);

        return $answer(200, [
            'export_metadata' => [
                'exported_at' => $now,
                'tenant_id' => $connection['tenant_id'],
                'customer_email' => $customerEmail,
                'customer_id' => $customerId,
                'data_retention_policy' => ['browse_events' => '14 months', 'orders' => '60 months from the order date'],
                'data_retention_purpose' => ['browse_events' => 'comparison with the same month last year', 'orders' => 'learning buying patterns'],
            ],
            'customer' => [
                'customer_id' => $customerId,
                'tenant_id' => $connection['tenant_id'],
                'email' => $customerEmail,
                'first_seen_at' => '2026-01-15T10:30:00Z',
                'opted_out' => $optOut['opt_out_status'],
                'opted_out_at' => $optOut['opt_out_status'] ? $optOut['effective_at'] : null,
                'language' => 'en',
            ],
            'orders' => [[
                'order_id' => $orderId,
                'tenant_id' => $connection['tenant_id'],
                'customer_id' => $customerId,
                'external_order_id' => $connection['prefix'] . 'mock-order-1',
                'ordered_at' => '2026-05-15T14:30:00.000Z',
                'total_amount' => '67.50',
                'currency' => 'EUR',
                'status' => 'completed',
            ]],
            'order_items' => [[
                'item_id' => 1,
                'order_id' => $orderId,
                'tenant_id' => $connection['tenant_id'],
                'sku' => $connection['prefix'] . 'mock-sku-1',
                'qty' => 1,
                'unit_price' => '67.50',
                'line_total' => '67.50',
            ]],
            'browse_events' => [],
            'email_events' => [],
            'recommendations' => [],
            'visitor_tokens' => [],
            'outcome_events' => [],
            'stock_alert_requests' => [],
        ]);
    }

    if ($route === 'customer_delete') {
        $state['deleted_customers'][] = $customerEmail;
        unset($state['opt_outs'][$customerEmail]);
        $write($state);

        // §9 "Response 200 OK".
        return $answer(200, [
            'ok' => true,
            'deleted' => true,
            'customer_email' => $customerEmail,
            'records_removed' => ['customer' => 1, 'orders' => 1, 'order_items' => 1, 'browse_events' => 0, 'email_events' => 0, 'outcome_events' => 0, 'recommendations' => 0, 'rec_attribution' => 0, 'visitor_tokens' => 0, 'trigger_candidates' => 0],
            'audit_log_id' => 'audit_' . bin2hex(random_bytes(8)),
            'deleted_at' => $now,
        ]);
    }

    if ($route === 'customer_opt_out') {
        if (!is_array($body) || !is_bool($body['opt_out'] ?? null)) {
            return $answer(400, ['error' => 'validation_failed', 'message' => 'One or more fields failed validation', 'timestamp' => $now]);
        }
        $state['opt_outs'][$customerEmail] = ['opt_out_status' => $body['opt_out'], 'effective_at' => $now];
        $write($state);

        // §10 "Response 200 OK".
        return $answer(200, [
            'ok' => true,
            'customer_email' => $customerEmail,
            'opt_out_status' => $body['opt_out'],
            'previous_status' => $optOut['opt_out_status'],
            'effective_at' => $now,
            'next_recommendations_cron' => gmdate('Y-m-d\\T05:00:00\\Z', time() + 86400),
        ]);
    }

    return $answer(404, ['error' => 'not_found', 'message' => 'No such route.', 'timestamp' => $now]);
}

// The storefront routes (Story 5.5): §6 browse events and §15 a shopper's recommendations.
if ($route === 'browse' || $route === 'recommendations') {
    if ($connection === null || $connection['revoked'] !== false) {
        return $answer(401, $unauthorised);
    }
    if (!empty($state['tenant_inactive'])) {
        return $answer(403, [
            'error' => 'tenant_inactive',
            'message' => 'This tenant is currently deactivated. Contact engine administrator.',
            'tenant_status' => 'suspended',
        ]);
    }
    // "Validation error example".
    $invalid = static function (array $details) use ($answer, $now): bool {
        return $answer(400, [
            'error' => 'validation_failed',
            'message' => 'One or more fields failed validation',
            'details' => $details,
            'request_id' => 'req_' . bin2hex(random_bytes(8)),
            'timestamp' => $now,
        ]);
    };
    $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    if ($route === 'browse') {
        // §6: the wrapper is all-or-nothing, each event is validated on its own.
        $events = is_array($body) ? ($body['events'] ?? null) : null;
        if (!is_array($events) || $events === [] || count($events) > 1000 || array_values($events) !== $events) {
            return $invalid(['events' => ['Expected an array of 1 to 1000 events']]);
        }
        $types = ['product_view', 'category_view', 'search', 'cart_add', 'cart_remove', 'wishlist_add', 'wishlist_remove', 'checkout_start', 'checkout_complete'];
        $seen = is_array($state['browse_event_ids'] ?? null) ? $state['browse_event_ids'] : [];
        $bound = is_array($state['visitor_tokens'] ?? null) ? $state['visitor_tokens'] : [];
        $errors = [];
        $deduplicated = $matched = $anonymous = 0;
        foreach ($events as $index => $event) {
            $field = null;
            if (!is_array($event)) {
                $field = 'event_id';
            } elseif (!is_string($event['event_id'] ?? null) || preg_match($uuid, $event['event_id']) !== 1) {
                $field = 'event_id';
            } elseif (!is_string($event['session_id'] ?? null) || $event['session_id'] === '') {
                $field = 'session_id';
            } elseif (!in_array($event['event_type'] ?? null, $types, true)) {
                $field = 'event_type';
            } elseif (!is_string($event['event_ts'] ?? null) || strtotime($event['event_ts']) === false) {
                $field = 'event_ts';
            } elseif (isset($event['smaily_rec_id']) && (!is_string($event['smaily_rec_id']) || preg_match($uuid, $event['smaily_rec_id']) !== 1)) {
                $field = 'smaily_rec_id';
            } elseif ($connection['prefix'] !== '' && isset($event['sku']) && strpos((string) $event['sku'], $connection['prefix']) !== 0) {
                // §6 `sku`, v1.33.0.
                $field = 'sku';
            }
            if ($field !== null) {
                $errors[] = ['index' => $index, 'field' => $field, 'message' => $field === 'event_type' ? 'Invalid enum value' : 'Invalid'];
                continue;
            }
            if (in_array($event['event_id'], $seen, true)) {
                ++$deduplicated;
                continue;
            }
            $seen[] = $event['event_id'];
            isset($event['smaily_visitor_token'], $bound[$event['smaily_visitor_token']]) ? ++$matched : ++$anonymous;
        }
        $state['browse_event_ids'] = $seen;
        $write($state);

        // §6 "Response 200 OK", with `deduplicated_all` when every event was a repeat.
        $payload = [
            'ok' => true,
            'processed' => $matched + $anonymous,
            'deduplicated' => $deduplicated,
            'errors' => $errors,
            'with_customer_match' => $matched,
            'anonymous' => $anonymous,
            'retroactive_bound' => 0,
            'duplicates_skipped' => $deduplicated,
        ];
        if ($deduplicated === count($events)) {
            $payload['deduplicated_all'] = true;
        }

        return $answer(200, $payload);
    }

    // §15. At least one identifier of 1 to 255 characters; the customer id wins over the token.
    $customerId = is_array($body) ? ($body['customer_external_id'] ?? null) : null;
    $token = is_array($body) ? ($body['smaily_visitor_token'] ?? null) : null;
    $identifier = static fn ($value): bool => is_string($value) && $value !== '' && strlen($value) <= 255;
    if (($customerId === null && $token === null)
        || ($customerId !== null && !$identifier($customerId))
        || ($token !== null && !$identifier($token))) {
        return $invalid(['customer_external_id' => ['Required: customer_external_id or smaily_visitor_token']]);
    }
    $limit = is_array($body) && array_key_exists('limit', $body) ? $body['limit'] : 9;
    if (!is_int($limit) || $limit < 1) {
        return $invalid(['limit' => ['Expected a positive integer']]);
    }
    $limit = min($limit, 9);
    $shoppers = is_array($state['recommendations'] ?? null) ? $state['recommendations'] : [];
    $given = $shoppers[$customerId !== null ? 'customer:' . $customerId : 'token:' . $token] ?? [];

    $slots = [];
    foreach (array_slice(is_array($given) ? array_values($given) : [], 0, $limit) as $index => $slot) {
        $slot = is_array($slot) ? $slot : [];
        $recId = $slot['rec_id'] ?? sprintf('3fa85f64-5717-4562-b3fc-%012d', $index + 1);
        $externalId = (string) ($slot['external_id'] ?? '');
        // §15 "Response 200 OK": the fields of a slot, in the contract's order.
        $slots[] = array_merge([
            'position' => $index + 1,
            'rec_id' => $recId,
            'sku' => $connection['prefix'] . $externalId,
            'external_id' => $externalId === '' ? null : $externalId,
            'name' => 'Recommended product ' . ($index + 1),
            'description' => 'A product the mock engine recommends.',
            'price' => 29.99,
            'compare_price' => null,
            'in_stock' => true,
            'image_url' => null,
            'product_url' => 'https://shop.example.test/product/' . rawurlencode($externalId) . '?smaily_rec=' . $recId . '&smaily_ctx=storefront',
            'headline' => null,
            'cta' => null,
            'reason' => null,
        ], $slot, ['position' => $index + 1]);
    }

    // §15 "Caching".
    header('Cache-Control: no-store, private');

    return $answer(200, ['slots' => $slots]);
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
