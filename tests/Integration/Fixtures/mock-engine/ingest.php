<?php
/**
 * The ingest routes of the engine mock (router.php includes this file in its own scope): the
 * catalog (§3), the product removal (§3b), the nightly product list (§3c), the customers (§4),
 * the orders (§5), the identity merge (§7) and the history import's progress (§25: `history`
 * holds each connection's import by its key's SHA-256, `history_calls` every call), each answered as
 * docs/RECENGINE_API_CONTRACT.md v1.37.1 states it. Every check and every answer below names the
 * sentence of the contract it comes from; the shapes were taken from the contract text, section by
 * section, not from another connector's mock.
 *
 * The mock validates what it is sent as the contract's field references do, so a request that a
 * test records has the contract's shape, and it keeps what the engine would keep, per engine store
 * (`tenant_id`): `catalog` (rows by `sku`, with `recommendable`), `customers` (by lower-case
 * address), `orders` (by `external_order_id`), `bound_tokens` (the customer each token is bound
 * to) and `manifests` (the answer to each connection's last list).
 *
 * Switches, beside router.php's:
 *
 *   fail    {catalog|catalog_remove|catalog_manifest|customers|orders|identity_merge: {status, error?}}
 *           the route answers this HTTP status ("Error handling")
 *   delay   {<route>: seconds}  the route waits before it answers
 *
 * Returns true when it answered the request, false when the request is not an ingest route.
 */
declare(strict_types=1);

$ingestRoutes = [
    'POST /api/v1/ingest/catalog' => 'catalog',
    'POST /api/v1/ingest/catalog/remove' => 'catalog_remove',
    'POST /api/v1/ingest/catalog/manifest' => 'catalog_manifest',
    'POST /api/v1/ingest/customers' => 'customers',
    'POST /api/v1/ingest/orders' => 'orders',
    'POST /api/v1/identity/merge' => 'identity_merge',
    'POST /api/v1/ingest/history/progress' => 'history_progress',
];
$ingestRoute = $ingestRoutes[$method . ' ' . $path] ?? null;
if ($ingestRoute === null) {
    return false;
}

$delay = (float) ($state['delay'][$ingestRoute] ?? 0);
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
$failure = $state['fail'][$ingestRoute] ?? null;
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
$prefix = (string) $connection['prefix'];

if ($ingestRoute === 'history_progress') {
    // §25 "Request body": the field table, and "Stopped import" rules 4 and 5.
    $phases = ['started', 'start_part_complete', 'complete'];
    $field = null;
    if (!is_array($body) || !is_string($body['import_id'] ?? null) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $body['import_id']) !== 1) {
        $field = 'import_id';
    } elseif (!in_array($body['phase'] ?? null, $phases, true)) {
        $field = 'phase';
    } elseif (!is_string($body['horizon_start'] ?? null) || strtotime($body['horizon_start']) === false) {
        $field = 'horizon_start';
    } elseif (array_key_exists('stop_reason', $body) && $body['stop_reason'] !== null
        && (!in_array($body['stop_reason'], ['cancelled', 'failed', 'stalled'], true) || $body['phase'] === 'complete')
    ) {
        $field = 'stop_reason';
    } else {
        foreach (['catalog', 'customers', 'orders'] as $entity) {
            $sent = $body['entities'][$entity]['sent'] ?? null;
            if (!is_int($sent) || $sent < 0) {
                $field = 'entities.' . $entity . '.sent';
                break;
            }
        }
    }
    if ($field !== null) {
        return $answer(400, ['error' => 'validation_failed', 'message' => 'One or more fields failed validation', 'details' => [['field' => $field]], 'timestamp' => $now]);
    }

    // "Per connection" (v1.33.0): the engine keeps the progress of each connection separately.
    $held = $state['history'][$key] ?? null;
    $stop = $body['stop_reason'] ?? null;
    $stale = false;
    if (!is_array($held) || $held['import_id'] !== $body['import_id']) {
        // Rule 2 and rule 5: another `import_id` replaces the import and starts over at its phase.
        $held = ['import_id' => $body['import_id'], 'phase' => $body['phase'], 'start_part_complete_at' => null, 'complete_at' => null, 'stop_reason' => null, 'stopped_at' => null];
    } elseif ($held['stop_reason'] !== null || ($stop !== null && $held['phase'] === 'complete')
        || array_search($body['phase'], $phases, true) < array_search($held['phase'], $phases, true)
    ) {
        // Rule 1 and "Stopped import" rules 3 and 4: nothing changed.
        $stale = true;
    } else {
        $held['phase'] = $body['phase'];
    }
    if (!$stale) {
        if ($held['phase'] !== 'started' && $held['start_part_complete_at'] === null) {
            $held['start_part_complete_at'] = $now;
        }
        if ($held['phase'] === 'complete' && $held['complete_at'] === null) {
            $held['complete_at'] = $now;
        }
        if ($stop !== null) {
            $held['stop_reason'] = $stop;
            $held['stopped_at'] = $now;
        }
        $held['entities'] = $body['entities'];
    }
    $state['history'][$key] = $held;
    $state['history_calls'][] = ['key' => $key] + $body;
    $write($state);
    unset($held['entities']);

    // §25 "Response 200 OK".
    return $answer(200, ['ok' => true, 'stale' => $stale] + $held + ['history' => HISTORY]);
}

// The prefixes of the store's other connections (§1 "Connection prefix").
$otherPrefixes = [];
foreach ($connections as $otherKey => $other) {
    if ($otherKey !== $key && $other['tenant_id'] === $tenant && $other['prefix'] !== '') {
        $otherPrefixes[] = (string) $other['prefix'];
    }
}
$otherPrefixes = array_values(array_unique(array_diff($otherPrefixes, [$prefix])));

/*
 * §3 "connection_prefix_mismatch": a key with a prefix may write only keys that start with it; a
 * key without a prefix may not write a key that starts with another connection's prefix.
 */
$inScope = static function ($value) use ($prefix, $otherPrefixes): bool {
    if (!is_string($value)) {
        return true;
    }
    if ($prefix !== '') {
        return strpos($value, $prefix) === 0;
    }
    foreach ($otherPrefixes as $otherPrefix) {
        if (strpos($value, $otherPrefix) === 0) {
            return false;
        }
    }

    return true;
};
// "Timezone": ISO 8601 with a zone, `Z` or a UTC offset; a timestamp without a zone is rejected.
$isTimestamp = static fn ($value): bool => is_string($value)
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/D', $value) === 1
    && strtotime($value) !== false;
$isNumber = static fn ($value): bool => is_int($value) || is_float($value);
$isUuid = static fn ($value): bool => is_string($value)
    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1;
// §3 "Multilingual variant": a non-empty string, or an object of language => non-empty string.
$isText = static function ($value): bool {
    if (is_string($value)) {
        return $value !== '';
    }
    if (!is_array($value) || $value === [] || array_keys($value) === range(0, count($value) - 1)) {
        return false;
    }
    foreach ($value as $text) {
        if (!is_string($text) || $text === '') {
            return false;
        }
    }

    return true;
};
// §3 "Wrapper is all-or-nothing": the 400 of a malformed wrapper.
$badWrapper = static fn (string $wrapper, string $message): array => [
    'error' => 'validation_failed',
    'details' => ['formErrors' => [], 'fieldErrors' => [$wrapper => [$message]]],
];
$isList = static fn ($value): bool => is_array($value) && array_keys($value) === range(0, count($value) - 1);
$wrapperOf = static function (string $wrapper, int $least, int $most) use ($body, $isList) {
    $items = is_array($body) ? ($body[$wrapper] ?? null) : null;
    if ($items === [] && $least === 0) {
        return [];
    }
    if (!$isList($items) && $items !== []) {
        return 'Expected array';
    }
    if (count($items) < $least || count($items) > $most) {
        return sprintf('Array must contain between %d and %d element(s)', $least, $most);
    }

    return $items;
};
/*
 * "Idempotency", layer 2: an item whose `event_id` was already seen is counted in `deduplicated`
 * and not written; a rejected item's `event_id` is not registered.
 */
$seen = is_array($state['event_ids'][$tenant] ?? null) ? $state['event_ids'][$tenant] : [];
// "Each item has exactly one fate", and the D6 answer with its invariant.
$d6 = static function (int $processed, int $deduplicated, array $errors, int $total, array $extra = []): array {
    $payload = ['ok' => true, 'processed' => $processed, 'deduplicated' => $deduplicated, 'errors' => $errors] + $extra;
    if ($total > 0 && $deduplicated === $total) {
        $payload['deduplicated_all'] = true;
    }

    return $payload;
};

if ($ingestRoute === 'catalog') {
    // §3: "A request carries 1–1000 products".
    $products = $wrapperOf('products', 1, 1000);
    if (is_string($products)) {
        return $answer(400, $badWrapper('products', $products));
    }
    $errors = [];
    $processed = 0;
    $deduplicated = 0;
    foreach ($products as $index => $product) {
        // §3 "Field reference", in its order; the first failing field names the error.
        $field = null;
        if (!is_array($product)) {
            $field = 'products';
        } elseif (isset($product['event_id']) && !$isUuid($product['event_id'])) {
            $field = 'event_id';
        } elseif (!is_string($product['sku'] ?? null) || $product['sku'] === '' || strlen($product['sku']) > 72) {
            $field = 'sku';
        } elseif (!$isText($product['name'] ?? null)) {
            $field = 'name';
        } elseif (!is_string($product['category_path'] ?? null) || $product['category_path'] === '') {
            $field = 'category_path';
        } elseif (!$isNumber($product['price'] ?? null)) {
            $field = 'price';
        } elseif (isset($product['compare_price']) && !$isNumber($product['compare_price'])) {
            $field = 'compare_price';
        } elseif (isset($product['on_sale_until']) && !$isTimestamp($product['on_sale_until'])) {
            $field = 'on_sale_until';
        } elseif (isset($product['currency']) && (!is_string($product['currency']) || preg_match('/^[A-Z]{3}$/D', $product['currency']) !== 1)) {
            $field = 'currency';
        } elseif (!is_bool($product['in_stock'] ?? null)) {
            $field = 'in_stock';
        } elseif (isset($product['description']) && !$isText($product['description'])) {
            $field = 'description';
        } elseif (isset($product['image_url']) && !$isText($product['image_url'])) {
            $field = 'image_url';
        } elseif (!$isText($product['product_url'] ?? null)) {
            $field = 'product_url';
        } elseif (isset($product['external_id']) && !is_string($product['external_id'])) {
            $field = 'external_id';
        } elseif (isset($product['tags']) && (!is_array($product['tags']) || ($product['tags'] !== [] && $isList($product['tags'])))) {
            $field = 'tags';
        } elseif (isset($product['raw_attributes']) && !is_array($product['raw_attributes'])) {
            $field = 'raw_attributes';
        } elseif (isset($product['product_type']) && !is_string($product['product_type'])) {
            $field = 'product_type';
        } elseif (isset($product['is_virtual']) && !is_bool($product['is_virtual'])) {
            $field = 'is_virtual';
        } elseif (isset($product['is_downloadable']) && !is_bool($product['is_downloadable'])) {
            $field = 'is_downloadable';
        } elseif (isset($product['websites'])) {
            // §3 "Several websites": an object of website code (1–64) => {product_url}.
            if (!is_array($product['websites']) || ($product['websites'] !== [] && $isList($product['websites']))) {
                $field = 'websites';
            } else {
                foreach ($product['websites'] as $code => $website) {
                    if ((string) $code === '' || strlen((string) $code) > 64 || !is_array($website) || !$isText($website['product_url'] ?? null)) {
                        $field = 'websites';
                    }
                }
            }
        }
        $error = null;
        if ($field === null && !$inScope($product['sku'])) {
            [$field, $error] = ['sku', 'connection_prefix_mismatch'];
        } elseif ($field === null && !$inScope($product['tags']['product_id'] ?? null)) {
            [$field, $error] = ['tags.product_id', 'connection_prefix_mismatch'];
        }
        if ($field !== null) {
            $errors[] = array_filter(
                ['index' => $index, 'sku' => is_array($product) && is_string($product['sku'] ?? null) ? $product['sku'] : null, 'field' => $field, 'error' => $error, 'message' => 'Invalid input'],
                static fn ($value): bool => $value !== null
            );
            continue;
        }
        if (isset($product['event_id']) && in_array($product['event_id'], $seen, true)) {
            ++$deduplicated;
            continue;
        }
        if (isset($product['event_id'])) {
            $seen[] = $product['event_id'];
        }
        // §3 "Send the whole row": the UPSERT replaces the row; `recommendable` is the engine's own.
        $state['catalog'][$tenant][$product['sku']] = $product + ['recommendable' => true, 'connection_prefix' => $prefix];
        ++$processed;
    }
    $state['event_ids'][$tenant] = $seen;
    $write($state);

    // §3 "Response 200 OK": `unmapped_attributes` is on every catalog answer.
    return $answer(200, $d6($processed, $deduplicated, $errors, count($products), ['unmapped_attributes' => []]));
}

if ($ingestRoute === 'catalog_remove') {
    // §3b: "`product_ids` missing / not an array / empty / >1000 → 400 validation_failed".
    $ids = $wrapperOf('product_ids', 1, 1000);
    if (is_string($ids)) {
        return $answer(400, $badWrapper('product_ids', $ids));
    }
    $removed = 0;
    $tombstoned = 0;
    $notFound = [];
    $errors = [];
    foreach ($ids as $id) {
        if (!is_string($id)) {
            return $answer(400, $badWrapper('product_ids', 'Expected string'));
        }
        // §3b "Per connection": an id of another connection is not removed.
        if (!$inScope($id)) {
            $errors[] = ['product_id' => $id, 'field' => 'product_ids', 'error' => 'connection_prefix_mismatch', 'message' => 'The product id belongs to another connection.'];
            continue;
        }
        $rows = 0;
        foreach (($state['catalog'][$tenant] ?? []) as $sku => $row) {
            if (($row['tags']['product_id'] ?? null) === $id && $row['connection_prefix'] === $prefix) {
                // §3b "Soft tombstone": in_stock=false and recommendable=false, the row is kept.
                $state['catalog'][$tenant][$sku]['in_stock'] = false;
                $state['catalog'][$tenant][$sku]['recommendable'] = false;
                ++$rows;
            }
        }
        if ($rows > 0) {
            ++$removed;
            $tombstoned += $rows;
        } else {
            $notFound[] = $id;
        }
    }
    $write($state);

    // §3b "Response 200 OK"; `errors` "is present only when there is one".
    return $answer(200, ['ok' => true, 'removed_products' => $removed, 'rows_tombstoned' => $tombstoned, 'not_found' => $notFound] + ($errors === [] ? [] : ['errors' => $errors]));
}

if ($ingestRoute === 'catalog_manifest') {
    // §3c: "`products` missing / not an array / more than 50,000 items" → 400; an empty list is taken.
    $products = $wrapperOf('products', 0, 50000);
    if (is_string($products)) {
        return $answer(400, $badWrapper('products', $products));
    }
    $list = [];
    foreach ($products as $item) {
        // §3c: "an item without a non-empty `sku` of at most 72 characters or without a boolean `in_stock`".
        if (!is_array($item) || !is_string($item['sku'] ?? null) || $item['sku'] === '' || strlen($item['sku']) > 72 || !is_bool($item['in_stock'] ?? null)) {
            return $answer(400, $badWrapper('products', 'Invalid item'));
        }
        // §3c: "A list holding an item outside the scope is rejected as a whole".
        if (!$inScope($item['sku'])) {
            return $answer(400, ['error' => 'connection_prefix_mismatch', 'message' => 'The list holds a product of another connection.']);
        }
        // §3c: "Same `sku` twice in the list: the last occurrence wins."
        $list[$item['sku']] = $item['in_stock'];
    }
    // §3c: the calling connection's own products, "removes, stock fixes, the missing count and the 20% guard are all computed within that scope".
    $own = [];
    foreach (($state['catalog'][$tenant] ?? []) as $sku => $row) {
        if ($inScope($sku)) {
            $own[$sku] = $row;
        }
    }
    $live = array_filter($own, static fn (array $row): bool => $row['recommendable'] !== false);
    $toRemove = array_keys(array_diff_key($live, $list));
    // §3c "Truncation guard": an empty list, or more than 20% of the live rows, removes nothing.
    $guard = null;
    if ($toRemove !== []) {
        if ($list === []) {
            $guard = 'empty_list';
        } elseif (count($toRemove) > 0.2 * count($live)) {
            $guard = 'too_many_removals';
        }
    } elseif ($list === []) {
        $guard = 'empty_list';
    }
    $removed = 0;
    $stockFixed = 0;
    $missing = 0;
    foreach ($list as $sku => $inStock) {
        if (!isset($own[$sku])) {
            ++$missing;
        } elseif ($own[$sku]['in_stock'] !== $inStock) {
            $state['catalog'][$tenant][$sku]['in_stock'] = $inStock;
            ++$stockFixed;
        }
    }
    if ($guard === null) {
        foreach ($toRemove as $sku) {
            $state['catalog'][$tenant][$sku]['in_stock'] = false;
            $state['catalog'][$tenant][$sku]['recommendable'] = false;
            ++$removed;
        }
    }
    // §3c "Response 200 OK".
    $result = [
        'ok' => true,
        'products_in_manifest' => count($list),
        'removed' => $removed,
        'stock_fixed' => $stockFixed,
        'missing_in_engine' => $missing,
        'guard_tripped' => $guard !== null,
        'guard_reason' => $guard,
        'would_remove' => count($toRemove),
    ];
    $state['manifests'][$key] = $result;
    $write($state);

    return $answer(200, $result);
}

if ($ingestRoute === 'customers') {
    // §4: "1..100 customers per request".
    $customers = $wrapperOf('customers', 1, 100);
    if (is_string($customers)) {
        return $answer(400, $badWrapper('customers', $customers));
    }
    $errors = [];
    $processed = 0;
    $deduplicated = 0;
    foreach ($customers as $index => $customer) {
        // §4 "Field reference".
        $field = null;
        if (!is_array($customer)) {
            $field = 'customers';
        } elseif (isset($customer['event_id']) && !$isUuid($customer['event_id'])) {
            $field = 'event_id';
        } elseif (!is_string($customer['email'] ?? null) || filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false) {
            $field = 'email';
        } elseif (isset($customer['first_seen_at']) && !$isTimestamp($customer['first_seen_at'])) {
            $field = 'first_seen_at';
        } else {
            foreach (['first_name', 'last_name', 'country', 'language', 'phone', 'external_id'] as $text) {
                if (isset($customer[$text]) && !is_string($customer[$text])) {
                    $field = $field ?? $text;
                }
            }
            // §4 "Note on consent": consent is not part of the customers contract. The mock
            // refuses it, so that a test sees a consent field that the module sends.
            foreach (array_keys($customer) as $name) {
                if (strpos((string) $name, 'consent') !== false || in_array($name, ['newsletter', 'optin', 'is_unsubscribed'], true)) {
                    $field = $field ?? (string) $name;
                }
            }
        }
        if ($field !== null) {
            // §4 "Error object shape": {index, email?, field, message}.
            $errors[] = array_filter(
                ['index' => $index, 'email' => is_array($customer) && is_string($customer['email'] ?? null) ? $customer['email'] : null, 'field' => $field, 'message' => 'Invalid ' . $field],
                static fn ($value): bool => $value !== null
            );
            continue;
        }
        if (isset($customer['event_id']) && in_array($customer['event_id'], $seen, true)) {
            ++$deduplicated;
            continue;
        }
        if (isset($customer['event_id'])) {
            $seen[] = $customer['event_id'];
        }
        // §4: "UPSERT by (tenant_id, email); email is lowercased on ingest"; "first_seen_at … earliest wins".
        $address = strtolower($customer['email']);
        $stored = $state['customers'][$tenant][$address] ?? [];
        $state['customers'][$tenant][$address] = ['email' => $address] + $customer + $stored;
        ++$processed;
    }
    $state['event_ids'][$tenant] = $seen;
    $write($state);

    // §4 "Response 200 OK".
    return $answer(200, $d6($processed, $deduplicated, $errors, count($customers)));
}

if ($ingestRoute === 'orders') {
    // §5: "1..50 orders per request".
    $orders = $wrapperOf('orders', 1, 50);
    if (is_string($orders)) {
        return $answer(400, $badWrapper('orders', $orders));
    }
    $errors = [];
    $skipped = [];
    $processed = 0;
    $deduplicated = 0;
    // §5 "History horizon": `now − config.history.horizon_months` (60 months).
    $horizon = strtotime('-' . HISTORY['horizon_months'] . ' months');
    foreach ($orders as $index => $order) {
        // §5 "Field reference".
        $field = null;
        $error = null;
        if (!is_array($order)) {
            $field = 'orders';
        } elseif (isset($order['event_id']) && !$isUuid($order['event_id'])) {
            $field = 'event_id';
        } elseif (!is_string($order['external_order_id'] ?? null) || $order['external_order_id'] === '') {
            $field = 'external_order_id';
        } elseif (!$inScope($order['external_order_id'])) {
            // §5: "An order of a prefixed key whose id does not start with the prefix is refused".
            [$field, $error] = ['external_order_id', 'connection_prefix_mismatch'];
        } elseif (!is_string($order['customer_email'] ?? null) || filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL) === false) {
            $field = 'customer_email';
        } elseif (!$isTimestamp($order['ordered_at'] ?? null)) {
            $field = 'ordered_at';
        } elseif (!$isNumber($order['total_amount'] ?? null)) {
            $field = 'total_amount';
        } elseif (isset($order['discount_amount']) && !$isNumber($order['discount_amount'])) {
            $field = 'discount_amount';
        } elseif (isset($order['currency']) && (!is_string($order['currency']) || preg_match('/^[A-Z]{3}$/D', $order['currency']) !== 1)) {
            $field = 'currency';
        } elseif (!in_array($order['status'] ?? null, ['completed', 'processing', 'cancelled', 'refunded'], true)) {
            $field = 'status';
        } elseif (array_key_exists('smaily_rec_id', $order) && !$isUuid($order['smaily_rec_id'])) {
            // §5: "a malformed value rejects the whole order"; `null` and "" too.
            $field = 'smaily_rec_id';
        } elseif (array_key_exists('channel', $order) && !in_array($order['channel'], ['online', 'in_store'], true)) {
            $field = 'channel';
        } elseif (!$isList($order['items'] ?? null) && ($order['items'] ?? null) !== []) {
            $field = 'items';
        } else {
            // §5 "Attribution fields on a later sync": `null` on any of them fails the order.
            foreach (['smaily_visitor_token', 'smaily_rec_ctx', 'session_id'] as $attribution) {
                if (array_key_exists($attribution, $order) && !is_string($order[$attribution])) {
                    $field = $field ?? $attribution;
                }
            }
            foreach ($order['items'] as $line) {
                if (!is_array($line) || !is_string($line['sku'] ?? null) || $line['sku'] === '' || !is_int($line['qty'] ?? null)
                    || !$isNumber($line['unit_price'] ?? null) || !$isNumber($line['line_total'] ?? null)
                    || (isset($line['discount_amount']) && !$isNumber($line['discount_amount']))
                    || (isset($line['returned_at']) && !$isTimestamp($line['returned_at']))
                ) {
                    $field = $field ?? 'items';
                }
            }
        }
        if ($field !== null) {
            // §5: errors as {index, external_order_id?, field, message}.
            $errors[] = array_filter([
                'index' => $index,
                'external_order_id' => is_array($order) && is_string($order['external_order_id'] ?? null) ? $order['external_order_id'] : null,
                'field' => $field,
                'error' => $error,
                'message' => 'Invalid ' . $field,
            ], static fn ($value): bool => $value !== null);
            continue;
        }
        if (strtotime($order['ordered_at']) < $horizon) {
            // §5: in `skipped_orders`, "not in errors[] … and its event_id is not registered".
            $skipped[] = ['index' => $index, 'external_order_id' => $order['external_order_id'], 'reason' => 'older_than_retention'];
            continue;
        }
        if (isset($order['event_id']) && in_array($order['event_id'], $seen, true)) {
            ++$deduplicated;
            continue;
        }
        if (isset($order['event_id'])) {
            $seen[] = $order['event_id'];
        }
        // §5 "Attribution fields on a later sync": an omitted field keeps the stored value; the
        // other columns and the lines are replaced.
        $stored = $state['orders'][$tenant][$order['external_order_id']] ?? [];
        foreach (['smaily_rec_id', 'smaily_rec_ctx', 'smaily_visitor_token', 'session_id'] as $attribution) {
            if (!array_key_exists($attribution, $order) && isset($stored[$attribution])) {
                $order[$attribution] = $stored[$attribution];
            }
        }
        $state['orders'][$tenant][$order['external_order_id']] = $order;
        // §5: the customer is "auto-created if absent", by the lower-case address.
        $address = strtolower($order['customer_email']);
        $state['customers'][$tenant][$address] = ($state['customers'][$tenant][$address] ?? []) + ['email' => $address];
        // §5 "Store-created visitor token": an unknown, well-formed `vs_` token is bound to the
        // order's customer; a token bound to another customer does not move.
        $token = $order['smaily_visitor_token'] ?? '';
        if (preg_match('/^vs_[A-Za-z0-9]{22}$/D', $token) === 1 && !isset($state['bound_tokens'][$tenant][$token])) {
            $state['bound_tokens'][$tenant][$token] = $address;
        }
        ++$processed;
    }
    $state['event_ids'][$tenant] = $seen;
    $write($state);

    // §5 "Response 200 OK": `skipped` and `skipped_orders` "are present only when" an order was older than the horizon.
    return $answer(200, $d6($processed, $deduplicated, $errors, count($orders), $skipped === [] ? [] : ['skipped' => count($skipped), 'skipped_orders' => $skipped]));
}

// §7.
$invalid = static fn (string $field): array => [
    'error' => 'validation_failed',
    'message' => 'One or more fields failed validation',
    'details' => ['errors' => [['field' => $field, 'code' => 'invalid', 'message' => 'Invalid ' . $field]]],
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
];
if (!is_array($body) || !is_string($body['customer_email'] ?? null) || $body['customer_email'] === '') {
    return $answer(400, $invalid('customer_email'));
}
// §7: "At least one of anon_session_id or smaily_visitor_token must be present".
$session = $body['anon_session_id'] ?? null;
$token = $body['smaily_visitor_token'] ?? null;
if ((!is_string($session) || $session === '') && (!is_string($token) || $token === '')) {
    return $answer(400, $invalid('smaily_visitor_token'));
}
// §7 `merge_ts`: "When sent, it must be a valid ISO 8601 timestamp … else 400 validation_failed".
if (array_key_exists('merge_ts', $body) && !$isTimestamp($body['merge_ts'])) {
    return $answer(400, $invalid('merge_ts'));
}
// §7 `merge_reason`: an enum of three values.
if (array_key_exists('merge_reason', $body) && !in_array($body['merge_reason'], ['user_logged_in', 'email_provided_at_checkout', 'manual_admin'], true)) {
    return $answer(400, $invalid('merge_reason'));
}
if (isset($body['customer_external_id']) && !is_string($body['customer_external_id'])) {
    return $answer(400, $invalid('customer_external_id'));
}
// §7: "The engine matches `customer_email` without regard to letter case".
$address = strtolower($body['customer_email']);
if (!isset($state['customers'][$tenant][$address])) {
    // §7 "Response 404 Not Found": the message names the address it was asked about.
    return $answer(404, [
        'error' => 'customer_not_found',
        'message' => 'No customer found with email ' . $body['customer_email'] . '. Send via POST /api/v1/ingest/customers first.',
    ]);
}
// §7 "Store-created token at login": an unknown `vs_` token is created for the customer, a token
// of the same customer is renewed, a token of another customer does not move, and an unknown
// `vt_` token or a malformed `vs_` value creates nothing.
$bound = 0;
if (is_string($token) && $token !== '') {
    $holder = $state['bound_tokens'][$tenant][$token] ?? null;
    if ($holder === null && preg_match('/^vs_[A-Za-z0-9]{22}$/D', $token) === 1) {
        $state['bound_tokens'][$tenant][$token] = $address;
        $holder = $address;
    }
    $bound = $holder === $address ? 1 : 0;
}
$state['merges'][$tenant][] = ['customer_email' => $address, 'smaily_visitor_token' => $token, 'bound' => $bound];
$write($state);

// §7 "Response 200 OK".
return $answer(200, [
    'ok' => true,
    'customer_id' => sprintf('%s-%s-4%s-8%s-%s', substr(md5($address), 0, 8), substr(md5($address), 8, 4), substr(md5($address), 13, 3), substr(md5($address), 17, 3), substr(md5($address), 20, 12)),
    'merged' => ['browse_events_updated' => 0, 'visitor_tokens_bound' => $bound, 'session_history_days' => 0],
]);
