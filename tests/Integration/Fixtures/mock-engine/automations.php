<?php
/**
 * The automation routes of the engine mock (router.php includes this file in its own scope): the
 * catalogue of the engine's automated emails (§11), the store's configuration rows (§12) and their
 * save (§13), each answered as docs/RECENGINE_API_CONTRACT.md v1.36.0 states it. Every check and
 * every answer below names the sentence of the contract it comes from; the shapes were taken from
 * the contract text, not from another connector's mock.
 *
 * The mock keeps what the engine would keep, per engine store (`tenant_id`): `automation_configs`
 * (the configuration rows by `trigger_key`).
 *
 * Switches, beside router.php's:
 *
 *   automations       list of §11 `triggers[]` items: the emails the engine offers the store now
 *                     (default: the two of DEFAULT_AUTOMATIONS). An item gives `key` and may give
 *                     any other field of a §11 item.
 *   automations_live  {<tenant_id>: list of trigger keys}  the emails whose real sends are on (the
 *                     go-live action of §20, or a Smaily operator): a save keeps them on (§13
 *                     "Real sends")
 *   fail              {automations_catalog|automations_config|automations_save: {status, error?}}
 *                     the route answers this HTTP status ("Error handling")
 *   delay             {<route>: seconds}  the route waits before it answers
 *
 * Returns true when it answered the request, false when the request is not an automation route.
 */
declare(strict_types=1);

// §11 "Response 200 OK": two emails, with the fields of the contract's example.
const DEFAULT_AUTOMATIONS = [
    [
        'key' => 'replenish_due',
        'name_et' => 'Taastäitumine',
        'name_en' => 'Replenishment due',
        'description_et' => 'Käivitub, kui kliendi korduvtoode hakkab ennustuse järgi otsa saama (85% isiklikust ostuintervallist täis).',
        'description_en' => 'Fires when a customer\'s recurring product is predicted to run out (85% of their personal purchase interval reached).',
    ],
    [
        'key' => 'winback_rescue',
        'name_et' => 'Tagasivõitmine',
        'name_en' => 'Win-back',
        'description_et' => 'Käivitub, kui klient on ohus mitte tagasi tulla.',
        'description_en' => 'Fires when a customer is at risk of not coming back.',
    ],
];

$automationRoutes = [
    'GET /api/v1/automations/catalog' => 'automations_catalog',
    'GET /api/v1/automations/config' => 'automations_config',
    'PUT /api/v1/automations/config' => 'automations_save',
];
$automationRoute = $automationRoutes[$method . ' ' . $path] ?? null;
if ($automationRoute === null) {
    return false;
}

$delay = (float) ($state['delay'][$automationRoute] ?? 0);
if ($delay > 0) {
    usleep((int) ($delay * 1000000));
    $state = $read();
}

// "Authentication": one answer for a missing header, an unknown key and a revoked key.
$connections = is_array($state['connections'] ?? null) ? $state['connections'] : [];
$connection = $key !== null ? ($connections[$key] ?? null) : null;
if ($connection === null || $connection['revoked'] !== false) {
    return $answer(401, ['error' => 'unauthorised', 'message' => 'Valid API key required (Authorization: Bearer <api_key>)']);
}
if (!empty($state['tenant_inactive'])) {
    // §2 "Response 403": every authenticated call of a deactivated store.
    return $answer(403, [
        'error' => 'tenant_inactive',
        'message' => 'This tenant is currently deactivated. Contact engine administrator.',
        'tenant_status' => 'suspended',
    ]);
}
$failure = $state['fail'][$automationRoute] ?? null;
if (is_array($failure)) {
    // "Error response format"; a 429 as "Rate limiting" prints it.
    $status = (int) $failure['status'];

    return $answer($status, [
        'error' => $failure['error'] ?? ($status === 429 ? 'rate_limit_exceeded' : 'internal_error'),
        'message' => $failure['message'] ?? 'The mock engine was told to fail.',
        'timestamp' => $now,
    ] + ($status === 429 ? ['retry_after_seconds' => 5] : []));
}

$tenant = (string) $connection['tenant_id'];
$triggers = [];
foreach (is_array($state['automations'] ?? null) ? $state['automations'] : DEFAULT_AUTOMATIONS as $trigger) {
    if (is_array($trigger) && is_string($trigger['key'] ?? null)) {
        // §11 "New field reference": the fields every catalogue email carries.
        $triggers[] = $trigger + ['engine_backed' => true, 'started_by' => 'engine', 'go_live' => 'store', 'editable_settings' => [], 'engine_rules' => []];
    }
}
$stored = is_array($state['automation_configs'][$tenant] ?? null) ? $state['automation_configs'][$tenant] : [];
$live = is_array($state['automations_live'][$tenant] ?? null) ? $state['automations_live'][$tenant] : [];

if ($automationRoute === 'automations_catalog') {
    // §11 "Response 200 OK" and "Top-level fields".
    return $answer(200, [
        'triggers' => $triggers,
        'language_modes' => ['single', 'per_language'],
        'docs' => 'https://intelligence.smaily.com/docs/en/smaily-templates',
    ]);
}

if ($automationRoute === 'automations_config') {
    // §12 "Response 200 OK": rows exist only for triggers that were configured at least once;
    // `setup` has one item for every email of the catalogue.
    $configs = [];
    foreach ($stored as $row) {
        // "automation_map: object {string: string}": an empty map is `{}`, never `[]`.
        $configs[] = ['automation_map' => (object) $row['automation_map']] + $row;
    }
    $setup = [];
    foreach ($triggers as $trigger) {
        $row = $stored[$trigger['key']] ?? null;
        $enabled = $row !== null && $row['enabled'];
        $workflow = $row !== null && $row['automation_map'] !== [];
        // §12 "setup_state values".
        $setup[] = [
            'trigger_key' => $trigger['key'],
            'setup_state' => $enabled ? ($row['test_mode'] ? 'test' : 'live') : ($workflow ? 'set_up' : 'off'),
            'stop_reason' => null,
            'go_live_blockers' => $workflow ? [] : ['no_workflow'],
            'live_since' => null,
            'daily_limit' => null,
            'last_test_send_at' => null,
            'sender_state' => 'unknown',
            'sender' => null,
            'sender_tested_at' => null,
            'design_state' => 'none',
            'settings' => $row === null ? (object) [] : ['cooldown_days' => $row['cooldown_days'], 'daily_cap' => $row['daily_cap']],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    return $answer(200, ['configs' => $configs, 'setup' => $setup]);
}

// §13. "Response 400 (body is not valid JSON)": no `message`, no `details`.
if (!is_array($body)) {
    return $answer(400, ['error' => 'invalid_json']);
}
// The body again with its objects kept, to tell `{}` from `[]`.
$typed = json_decode($raw);
$rows = $body['configs'] ?? null;
// "Wrapper": 1..50 rows; a failure of the wrapper has no `index` and `field: "configs"`.
if (!is_array($rows) || $rows === [] || count($rows) > 50 || array_keys($rows) !== range(0, count($rows) - 1)) {
    return $answer(422, ['error' => 'validation_failed', 'errors' => [['field' => 'configs', 'message' => 'Expected 1 to 50 rows']]]);
}

$known = array_column($triggers, 'key');
$errors = [];
$accepted = [];
foreach ($rows as $index => $row) {
    if (!is_array($row)) {
        // "field: unknown if the row itself is not an object".
        $errors[] = ['index' => $index, 'field' => 'unknown', 'message' => 'Expected object'];
        continue;
    }
    $triggerKey = is_string($row['trigger_key'] ?? null) ? $row['trigger_key'] : null;
    $fault = static function (string $field, string $message) use (&$errors, $index, $triggerKey): void {
        $errors[] = ['index' => $index] + ($triggerKey !== null ? ['trigger_key' => $triggerKey] : []) + ['field' => $field, 'message' => $message];
    };
    // "trigger_key: a §11 catalogue key. Any other key → 422 (tundmatu trigger)".
    if ($triggerKey === null) {
        $fault('trigger_key', 'Required');
    } elseif (!in_array($triggerKey, $known, true)) {
        $fault('trigger_key', 'tundmatu trigger');
    }
    // "Every key below is required, except automation_map, test_mode and test_emails".
    if (!is_bool($row['enabled'] ?? null)) {
        $fault('enabled', 'Required');
    }
    if (!in_array($row['language_mode'] ?? null, ['single', 'per_language'], true)) {
        $fault('language_mode', 'Required');
    }
    if (!is_int($row['cooldown_days'] ?? null) || $row['cooldown_days'] < 1 || $row['cooldown_days'] > 365) {
        $fault('cooldown_days', 'Expected an integer from 1 to 365');
    }
    // "daily_cap: nullable but the key must be present".
    if (!array_key_exists('daily_cap', $row)
        || !($row['daily_cap'] === null || (is_int($row['daily_cap']) && $row['daily_cap'] >= 1 && $row['daily_cap'] <= 100000))) {
        $fault('daily_cap', 'Expected an integer from 1 to 100000, or null');
    }
    if (array_key_exists('test_mode', $row) && !is_bool($row['test_mode'])) {
        $fault('test_mode', 'Expected boolean');
    }
    if (array_key_exists('test_emails', $row)) {
        if (!is_array($row['test_emails']) || count($row['test_emails']) > 50) {
            $fault('test_emails', 'Expected at most 50 addresses');
        } else {
            foreach (array_values($row['test_emails']) as $position => $email) {
                if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $fault('test_emails.' . $position, 'Invalid email');
                }
            }
        }
    }
    // "automation_map: every value must be a numeric string"; when omitted, the stored map is kept.
    $map = $stored[$triggerKey]['automation_map'] ?? [];
    if (array_key_exists('automation_map', $row)) {
        $typedMap = $typed->configs[$index]->automation_map ?? null;
        if (!is_object($typedMap)) {
            $fault('automation_map', 'Expected object');
        } else {
            $map = $row['automation_map'];
            foreach ($map as $value) {
                if (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
                    $fault('automation_map', 'automatsiooni id peab olema number');
                    break;
                }
            }
        }
    }
    // "When enabled=true, the stored-or-sent map must satisfy the v1.14.0 rule".
    if (($row['enabled'] ?? null) === true && is_array($map)) {
        if (($row['language_mode'] ?? null) === 'single' && !isset($map['id'])) {
            $fault('automation_map', 'automation_map.id on nõutav');
        } elseif (($row['language_mode'] ?? null) === 'per_language' && !isset($map['fallback'])) {
            $fault('automation_map', 'automation_map.fallback on nõutav');
        }
    }

    $previous = $stored[$triggerKey] ?? null;
    // "An omitted optional key keeps its stored value"; a new row is stored with `true` and `[]`.
    $testMode = array_key_exists('test_mode', $row) ? $row['test_mode'] : ($previous['test_mode'] ?? true);
    // "Legacy field behaviour": enabled with `test_mode: false` is stored with `test_mode: true`
    // unless the email already sends to real customers.
    if (($row['enabled'] ?? null) === true && $testMode === false && !in_array($triggerKey, $live, true)) {
        $testMode = true;
    }
    $accepted[(string) $triggerKey] = [
        'trigger_key' => $triggerKey,
        'enabled' => $row['enabled'] ?? false,
        'language_mode' => $row['language_mode'] ?? 'single',
        'automation_map' => is_array($map) ? $map : [],
        'cooldown_days' => $row['cooldown_days'] ?? null,
        'daily_cap' => $row['daily_cap'] ?? null,
        'test_mode' => $testMode,
        'test_emails' => array_key_exists('test_emails', $row) ? $row['test_emails'] : ($previous['test_emails'] ?? []),
        // §13 "Semantics": written as 'plugin' on every row this endpoint touches.
        'configured_via' => 'plugin',
        'updated_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
    ];
}

// "Validation is all-or-nothing": any invalid row → 422 and nothing is written.
if ($errors !== []) {
    return $answer(422, ['error' => 'validation_failed', 'errors' => $errors]);
}

// "PUT never deletes rows": a trigger absent from the body keeps its stored config.
$state['automation_configs'][$tenant] = $accepted + $stored;
// "enabled: false and test_mode: true are always stored as sent, so both switch real sends off".
$state['automations_live'][$tenant] = array_values(array_filter(
    $live,
    static fn ($liveKey): bool => !isset($accepted[$liveKey]) || ($accepted[$liveKey]['enabled'] && !$accepted[$liveKey]['test_mode'])
));
$write($state);

// §13 "Response 200 OK".
return $answer(200, ['ok' => true, 'upserted' => count($rows)]);
