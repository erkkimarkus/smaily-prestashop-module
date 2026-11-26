<?php
/**
 * The automation routes of the engine mock (router.php includes this file in its own scope): the
 * catalogue of the engine's automated emails (§11), the store's configuration rows (§12) and their
 * save (§13), an email's test send (§19) and its real-sends switch (§20), each answered as
 * docs/RECENGINE_API_CONTRACT.md v1.37.2 states it. Every check and every answer below names the
 * sentence of the contract it comes from; the shapes were taken from the contract text, not from
 * another connector's mock.
 *
 * The mock keeps what the engine would keep, per engine store (`tenant_id`): `automation_configs`
 * (the configuration rows by `trigger_key`), `automations_live` and `automations_live_since`.
 * A test send's address stays only in the request that router.php records.
 *
 * Switches, beside router.php's:
 *
 *   automations       list of §11 `triggers[]` items: the emails the engine offers the store now
 *                     (default: the two of DEFAULT_AUTOMATIONS). An item gives `key` and may give
 *                     any other field of a §11 item.
 *   automations_live  {<tenant_id>: list of trigger keys}  the emails whose real sends are on (the
 *                     go-live action of §20, or a Smaily operator): a save keeps them on (§13
 *                     "Real sends")
 *   automations_live_since  {<tenant_id>: {<key>: ISO 8601}}  when §20 switched them on
 *   automations_stopped     {<tenant_id>: list of trigger keys}  the emails that the engine stopped
 *                     and a Smaily operator has not reviewed (§12 `stopped`, §20 "Automatic stop")
 *   automations_blockers    {<tenant_id>: {<key>: list of codes}}  more §12 `go_live_blockers`
 *                     (for example `missing_subject`) beside the ones the mock finds itself
 *   automations_notice      {<tenant_id>: {<key>: code}}  the §12 `notice` of an enabled email
 *   automations_daily_limit {<tenant_id>: {<key>: integer}}  today's §12 `daily_limit` of a live
 *                     email (default: the ramp's first step, 10, or a lower `daily_cap`)
 *   fail              {automations_catalog|automations_config|automations_save|
 *                     automations_test_send|automations_go_live: {status, error?, details?}}
 *                     the route answers this HTTP status ("Error handling"), with `details`
 *                     where given (§19 `{"reasons": [...]}`, `{"sender_state": ...}`)
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
// §19 and §20: the email's key stands in the path, URL-encoded.
$emailKey = null;
if ($automationRoute === null && $method === 'POST' && preg_match('~^/api/v1/automations/([^/]+)/(test-send|go-live)$~', $path, $emailMatch) === 1) {
    $automationRoute = $emailMatch[2] === 'test-send' ? 'automations_test_send' : 'automations_go_live';
    $emailKey = rawurldecode($emailMatch[1]);
}
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
    ] + (isset($failure['details']) ? ['details' => $failure['details']] : []) + ($status === 429 ? ['retry_after_seconds' => 5] : []));
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
$liveSince = is_array($state['automations_live_since'][$tenant] ?? null) ? $state['automations_live_since'][$tenant] : [];
$stopped = is_array($state['automations_stopped'][$tenant] ?? null) ? $state['automations_stopped'][$tenant] : [];
// §12 `go_live_blockers`: `no_workflow` without a bound workflow, `stopped_needs_review` after a stop
// that no operator reviewed, and the ones a walk names.
$blockersOf = static function (string $triggerKey) use ($stored, $stopped, $state, $tenant): array {
    $row = $stored[$triggerKey] ?? null;
    $blockers = $row !== null && $row['automation_map'] !== [] ? [] : ['no_workflow'];
    if (in_array($triggerKey, $stopped, true)) {
        $blockers[] = 'stopped_needs_review';
    }
    $more = $state['automations_blockers'][$tenant][$triggerKey] ?? [];

    return array_values(array_unique(array_merge($blockers, is_array($more) ? $more : [])));
};
$isLive = static fn (string $triggerKey): bool => in_array($triggerKey, $live, true) && !in_array($triggerKey, $stopped, true)
    && ($stored[$triggerKey]['enabled'] ?? false) === true;
// §12 `daily_limit`: `live` only; the ramp's first step (§20 "Ramp"), or a lower `daily_cap`.
$dailyLimitOf = static function (string $triggerKey) use ($stored, $state, $tenant): int {
    $given = $state['automations_daily_limit'][$tenant][$triggerKey] ?? null;
    $cap = $stored[$triggerKey]['daily_cap'] ?? null;

    return is_int($given) ? $given : (is_int($cap) ? min(10, $cap) : 10);
};

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
        $notice = $state['automations_notice'][$tenant][$trigger['key']] ?? null;
        // §12 "setup_state values": a stop stays until an operator reviews it.
        if (in_array($trigger['key'], $stopped, true)) {
            $setupState = 'stopped';
        } elseif ($enabled) {
            $setupState = $isLive($trigger['key']) ? 'live' : 'test';
        } else {
            $setupState = $workflow ? 'set_up' : 'off';
        }
        $setup[] = [
            'trigger_key' => $trigger['key'],
            'setup_state' => $setupState,
            'stop_reason' => $setupState === 'stopped' ? 'auto_stop_complaint' : null,
            'go_live_blockers' => $blockersOf($trigger['key']),
            // `notice`: an enabled email (`test` or `live`) whose workflow is not active.
            'notice' => in_array($setupState, ['test', 'live'], true) && is_string($notice) ? $notice : null,
            'live_since' => $setupState === 'live' ? ($liveSince[$trigger['key']] ?? null) : null,
            'daily_limit' => $setupState === 'live' ? $dailyLimitOf($trigger['key']) : null,
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

// §13, §19, §20. "Response 400 (body is not valid JSON)": no `message`, no `details`.
if (!is_array($body)) {
    return $answer(400, ['error' => 'invalid_json']);
}

if ($emailKey !== null) {
    // §19, §20: "`{key}` is a §11 catalogue key"; any other → 404 `unknown_automation`.
    if (!in_array($emailKey, array_column($triggers, 'key'), true)) {
        return $answer(404, ['error' => 'unknown_automation', 'message' => 'Unknown automation']);
    }
}

if ($automationRoute === 'automations_test_send') {
    // §19 "Request body": `email` required, max 254; `language` optional, ISO 639-1.
    $email = $body['email'] ?? null;
    $fields = [];
    if (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $fields[] = ['field' => 'email', 'message' => 'Invalid email'];
    }
    if (array_key_exists('language', $body) && (!is_string($body['language']) || preg_match('/^[a-z]{2}$/D', $body['language']) !== 1)) {
        $fields[] = ['field' => 'language', 'message' => 'Invalid language'];
    }
    if ($fields !== []) {
        return $answer(422, ['error' => 'validation_failed', 'errors' => $fields]);
    }
    // "No workflow, no test": an email without a workflow answers 409 `not_ready`.
    if (($stored[$emailKey]['automation_map'] ?? []) === []) {
        return $answer(409, ['error' => 'not_ready', 'message' => 'Email is not ready', 'details' => ['reasons' => ['no_workflow']]]);
    }

    // §19 "Response 200 OK": the answer does not repeat the recipient address.
    return $answer(200, ['ok' => true, 'trigger_key' => $emailKey, 'accepted_at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'sender' => null]);
}

if ($automationRoute === 'automations_go_live') {
    // §20 "Request body" and 422 `validation_failed`.
    $fields = [];
    if (!is_bool($body['live'] ?? null)) {
        $fields[] = ['field' => 'live', 'message' => 'Required'];
    } elseif ($body['live'] && ($body['confirmed'] ?? null) !== true) {
        $fields[] = ['field' => 'confirmed', 'message' => 'Must be true'];
    }
    if (array_key_exists('switched_by', $body) && (!is_string($body['switched_by']) || strlen($body['switched_by']) > 100)) {
        $fields[] = ['field' => 'switched_by', 'message' => 'Invalid'];
    }
    if ($fields !== []) {
        return $answer(422, ['error' => 'validation_failed', 'errors' => $fields]);
    }
    $changed = false;
    if ($body['live']) {
        // "`live: true`": any blocker → 409 `go_live_blocked`, and nothing changes.
        $blockers = $blockersOf($emailKey);
        if ($blockers !== []) {
            return $answer(409, ['error' => 'go_live_blocked', 'message' => 'Real sends cannot be switched on', 'details' => ['blockers' => $blockers]]);
        }
        if (!$isLive($emailKey)) {
            // "the engine stores `enabled: true` and `test_mode: false`"; the ramp starts again.
            $state['automation_configs'][$tenant][$emailKey] = array_replace($stored[$emailKey], ['enabled' => true, 'test_mode' => false, 'updated_at' => gmdate('Y-m-d\TH:i:s.000\Z')]);
            $state['automations_live'][$tenant] = array_values(array_unique(array_merge($live, [$emailKey])));
            $state['automations_live_since'][$tenant][$emailKey] = gmdate('Y-m-d\TH:i:s.000\Z');
            unset($state['automations_daily_limit'][$tenant][$emailKey]);
            $changed = true;
        }
    } elseif ($isLive($emailKey)) {
        // "`live: false`": the engine stores `test_mode: true`; `enabled` is unchanged.
        $state['automation_configs'][$tenant][$emailKey] = array_replace($stored[$emailKey], ['test_mode' => true, 'updated_at' => gmdate('Y-m-d\TH:i:s.000\Z')]);
        $state['automations_live'][$tenant] = array_values(array_diff($live, [$emailKey]));
        unset($state['automations_live_since'][$tenant][$emailKey]);
        $changed = true;
    }
    $write($state);
    // A switch-on that passed the blockers is live now; a switch-off never is.
    $nowLive = $body['live'];
    $storedState = in_array($emailKey, $stopped, true) ? 'stopped' : ($nowLive ? 'live' : (($stored[$emailKey]['enabled'] ?? false) ? 'test' : 'set_up'));

    // §20 "Response 200 OK": `setup_state`, `live_since` and `daily_limit` have the §12 meaning.
    return $answer(200, [
        'ok' => true,
        'trigger_key' => $emailKey,
        'changed' => $changed,
        'setup_state' => $storedState,
        'live_since' => $nowLive ? ($state['automations_live_since'][$tenant][$emailKey] ?? null) : null,
        'daily_limit' => $nowLive ? $dailyLimitOf($emailKey) : null,
    ]);
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
