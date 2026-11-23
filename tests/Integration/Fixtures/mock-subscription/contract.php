<?php
/**
 * The report body's rules of docs/contracts/installation-report-v1.md v1.2.0, written from the
 * contract's text ("Products", "Report body", "Addresses", "Error codes in a report"), not from
 * the module's builder. The mock receiver (router.php) answers with them, and the tests hold the
 * builder's body against them.
 *
 * installation_report_errors() answers the contract's `details.errors[]` of a body: a field path
 * and one of the codes `required`, `invalid_type`, `invalid_value`, `too_long`, `too_many`,
 * `duplicate`, `not_allowed`; [] for a valid body. A product outside the registry is the one error
 * `product: unknown_product`.
 *
 * installation_report_unknown_fields() lists the fields of a body that the contract does not name.
 * The service ignores them; a report of this module has none ("What a report never carries").
 */
declare(strict_types=1);

const INSTALLATION_REPORT_PRODUCTS = [
    'connect_woocommerce' => ['platforms' => ['wordpress', 'woocommerce'], 'php' => true, 'website' => false],
    'connect_magento' => ['platforms' => ['magento'], 'php' => true, 'website' => true],
    'connect_prestashop' => ['platforms' => ['prestashop'], 'php' => true, 'website' => true],
    'connect_shopify' => ['platforms' => ['shopify'], 'php' => false, 'website' => false],
];

const INSTALLATION_REPORT_FIELDS = [
    'top' => ['product', 'product_version', 'platforms', 'php_version', 'installation_url', 'smaily_subdomain', 'event', 'trigger', 'sent_at', 'connections'],
    'platform' => ['name', 'version'],
    'connection' => ['role', 'store_url', 'smaily_subdomain', 'website', 'language', 'features', 'smaily', 'intelligence'],
    'smaily' => ['status', 'last_sync_at', 'last_error_code'],
    'intelligence' => ['tenant_id', 'connected', 'status', 'last_sync_at', 'last_error_code'],
];

/**
 * @param array<mixed> $body the decoded JSON body
 *
 * @return list<array{field: string, code: string}>
 */
function installation_report_errors(array $body): array
{
    $errors = [];
    $add = static function (string $field, string $code) use (&$errors): void {
        $errors[] = ['field' => $field, 'code' => $code];
    };
    $text = static function (array $in, string $key, string $field, int $max) use ($add): ?string {
        if (!array_key_exists($key, $in)) {
            $add($field, 'required');

            return null;
        }
        if (!is_string($in[$key])) {
            $add($field, 'invalid_type');

            return null;
        }
        if ($in[$key] === '') {
            $add($field, 'invalid_value');

            return null;
        }
        if (strlen($in[$key]) > $max) {
            $add($field, 'too_long');

            return null;
        }

        return $in[$key];
    };
    $subdomain = static function (array $in, string $field) use ($add, $text): ?string {
        $value = $text($in, 'smaily_subdomain', $field, 63);
        if ($value !== null && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $value) !== 1) {
            $add($field, 'invalid_value');

            return null;
        }

        return $value;
    };
    $address = static function (array $in, string $key, string $field) use ($add, $text): ?string {
        $value = $text($in, $key, $field, 255);
        if ($value === null) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https', 'HTTP', 'HTTPS'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strpos($value, '?') !== false || strpos($value, '#') !== false
        ) {
            $add($field, 'invalid_value');

            return null;
        }

        return $value;
    };
    $isTimestamp = static fn ($value): bool => is_string($value)
        && preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?Z$/D', $value, $m) === 1
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 61;
    $health = static function (array $in, string $field) use ($add, $isTimestamp): void {
        $status = $in['status'] ?? null;
        if (!array_key_exists('status', $in)) {
            $add($field . '.status', 'required');
        } elseif (!is_string($status)) {
            $add($field . '.status', 'invalid_type');
        } elseif (!in_array($status, ['ok', 'failing'], true)) {
            $add($field . '.status', 'invalid_value');
        }
        if (!array_key_exists('last_sync_at', $in)) {
            $add($field . '.last_sync_at', 'required');
        } elseif ($in['last_sync_at'] !== null && !is_string($in['last_sync_at'])) {
            $add($field . '.last_sync_at', 'invalid_type');
        } elseif ($in['last_sync_at'] !== null && !$isTimestamp($in['last_sync_at'])) {
            $add($field . '.last_sync_at', 'invalid_value');
        }
        if (!array_key_exists('last_error_code', $in)) {
            $add($field . '.last_error_code', 'required');
        } elseif ($in['last_error_code'] !== null && !is_string($in['last_error_code'])) {
            $add($field . '.last_error_code', 'invalid_type');
        } elseif (is_string($in['last_error_code']) && preg_match('/^[a-z0-9_]{1,64}$/D', $in['last_error_code']) !== 1) {
            $add($field . '.last_error_code', 'invalid_value');
        } elseif (($status === 'ok') !== ($in['last_error_code'] === null) && in_array($status, ['ok', 'failing'], true)) {
            // "The error code of the failure that makes status failing. null when status is ok."
            $add($field . '.last_error_code', 'invalid_value');
        }
    };

    $product = $body['product'] ?? null;
    if (!array_key_exists('product', $body)) {
        $add('product', 'required');
    } elseif (!is_string($product)) {
        $add('product', 'invalid_type');
    } elseif (!isset(INSTALLATION_REPORT_PRODUCTS[$product])) {
        return [['field' => 'product', 'code' => 'unknown_product']];
    }
    $registry = is_string($product) ? (INSTALLATION_REPORT_PRODUCTS[$product] ?? null) : null;

    $text($body, 'product_version', 'product_version', 32);

    if (!array_key_exists('platforms', $body)) {
        $add('platforms', 'required');
    } elseif (!is_array($body['platforms']) || !array_is_list_($body['platforms'])) {
        $add('platforms', 'invalid_type');
    } elseif ($body['platforms'] === []) {
        $add('platforms', 'required');
    } elseif (count($body['platforms']) > 5) {
        $add('platforms', 'too_many');
    } else {
        $names = [];
        foreach ($body['platforms'] as $i => $platform) {
            $field = 'platforms[' . $i . ']';
            if (!is_array($platform)) {
                $add($field, 'invalid_type');
                continue;
            }
            $name = $text($platform, 'name', $field . '.name', 64);
            if ($name !== null && $registry !== null && !in_array($name, $registry['platforms'], true)) {
                $add($field . '.name', 'invalid_value');
            } elseif ($name !== null && isset($names[$name])) {
                $add($field . '.name', 'duplicate');
            }
            if ($name !== null) {
                $names[$name] = true;
            }
            $text($platform, 'version', $field . '.version', 32);
        }
    }

    if ($registry !== null && $registry['php']) {
        $text($body, 'php_version', 'php_version', 32);
    } elseif ($registry !== null && array_key_exists('php_version', $body)) {
        $add('php_version', 'not_allowed');
    }

    $address($body, 'installation_url', 'installation_url');
    $main = $subdomain($body, 'smaily_subdomain');

    $event = 'snapshot';
    if (array_key_exists('event', $body)) {
        if (!is_string($body['event'])) {
            $add('event', 'invalid_type');
        } elseif (!in_array($body['event'], ['snapshot', 'disconnected', 'deactivated'], true)) {
            $add('event', 'invalid_value');
        } else {
            $event = $body['event'];
        }
    }
    if ($event === 'snapshot') {
        if (!array_key_exists('trigger', $body)) {
            $add('trigger', 'required');
        } elseif (!is_string($body['trigger'])) {
            $add('trigger', 'invalid_type');
        } elseif (!in_array($body['trigger'], ['setup_saved', 'daily'], true)) {
            $add('trigger', 'invalid_value');
        }
    } elseif (array_key_exists('trigger', $body)) {
        $add('trigger', 'not_allowed');
    }

    if (!array_key_exists('sent_at', $body)) {
        $add('sent_at', 'required');
    } elseif (!is_string($body['sent_at'])) {
        $add('sent_at', 'invalid_type');
    } elseif (!$isTimestamp($body['sent_at'])) {
        $add('sent_at', 'invalid_value');
    }

    if (!array_key_exists('connections', $body)) {
        $add('connections', 'required');

        return $errors;
    }
    if (!is_array($body['connections']) || !array_is_list_($body['connections'])) {
        $add('connections', 'invalid_type');

        return $errors;
    }
    if ($body['connections'] === [] && $event !== 'disconnected') {
        $add('connections', 'required');
    }
    if (count($body['connections']) > 100) {
        $add('connections', 'too_many');
    }

    $seen = [];
    $marketing = [];
    foreach ($body['connections'] as $i => $connection) {
        $field = 'connections[' . $i . ']';
        if (!is_array($connection)) {
            $add($field, 'invalid_type');
            continue;
        }
        $role = $text($connection, 'role', $field . '.role', 64);
        if ($role !== null && !in_array($role, ['marketing', 'transactional'], true)) {
            $add($field . '.role', 'invalid_value');
        }
        $storeUrl = $address($connection, 'store_url', $field . '.store_url');
        $account = $subdomain($connection, $field . '.smaily_subdomain');
        if ($role === 'marketing' && $account !== null) {
            $marketing[$account] = true;
        }
        $website = null;
        if ($registry !== null && $registry['website']) {
            $website = $text($connection, 'website', $field . '.website', 64);
        } elseif ($registry !== null && array_key_exists('website', $connection)) {
            $add($field . '.website', 'not_allowed');
        }
        $language = null;
        if (array_key_exists('language', $connection)) {
            if (!is_string($connection['language'])) {
                $add($field . '.language', 'invalid_type');
            } elseif (preg_match('/^[a-z]{2}$/D', $connection['language']) !== 1) {
                $add($field . '.language', 'invalid_value');
            } else {
                $language = $connection['language'];
            }
        }
        $key = json_encode([$role, $storeUrl === null ? null : rtrim(strtolower($storeUrl), '/'), $website, $language]);
        if (isset($seen[$key])) {
            $add($field, 'duplicate');
        }
        $seen[$key] = true;

        if (!array_key_exists('features', $connection)) {
            $add($field . '.features', 'required');
        } elseif (!is_array($connection['features']) || !array_is_list_($connection['features'])) {
            $add($field . '.features', 'invalid_type');
        } elseif (count($connection['features']) > 50) {
            $add($field . '.features', 'too_many');
        } else {
            $features = [];
            foreach ($connection['features'] as $j => $feature) {
                if (!is_string($feature)) {
                    $add($field . '.features[' . $j . ']', 'invalid_type');
                } elseif (preg_match('/^[a-z0-9_]{1,64}$/D', $feature) !== 1) {
                    $add($field . '.features[' . $j . ']', 'invalid_value');
                } elseif (isset($features[$feature])) {
                    $add($field . '.features[' . $j . ']', 'duplicate');
                }
                if (is_string($feature)) {
                    $features[$feature] = true;
                }
            }
        }

        if (!array_key_exists('smaily', $connection)) {
            $add($field . '.smaily', 'required');
        } elseif (!is_array($connection['smaily'])) {
            $add($field . '.smaily', 'invalid_type');
        } else {
            $health($connection['smaily'], $field . '.smaily');
        }

        if (array_key_exists('intelligence', $connection)) {
            $intelligence = $connection['intelligence'];
            if (!is_array($intelligence)) {
                $add($field . '.intelligence', 'invalid_type');
            } else {
                $text($intelligence, 'tenant_id', $field . '.intelligence.tenant_id', 255);
                if (!array_key_exists('connected', $intelligence)) {
                    $add($field . '.intelligence.connected', 'required');
                } elseif (!is_bool($intelligence['connected'])) {
                    $add($field . '.intelligence.connected', 'invalid_type');
                }
                $health($intelligence, $field . '.intelligence');
            }
        }
    }

    // "In a snapshot report it equals the smaily_subdomain of at least one marketing connection."
    if ($event === 'snapshot' && $main !== null && $body['connections'] !== [] && !isset($marketing[$main])) {
        $add('smaily_subdomain', 'invalid_value');
    }

    return $errors;
}

/**
 * @param array<mixed> $body the decoded JSON body
 *
 * @return list<string> the paths of the fields that the contract does not name
 */
function installation_report_unknown_fields(array $body): array
{
    $unknown = [];
    $check = static function (array $in, string $kind, string $prefix) use (&$unknown): void {
        foreach (array_keys($in) as $key) {
            if (!in_array($key, INSTALLATION_REPORT_FIELDS[$kind], true)) {
                $unknown[] = $prefix . $key;
            }
        }
    };
    $check($body, 'top', '');
    foreach (is_array($body['platforms'] ?? null) ? $body['platforms'] : [] as $i => $platform) {
        if (is_array($platform)) {
            $check($platform, 'platform', 'platforms[' . $i . '].');
        }
    }
    foreach (is_array($body['connections'] ?? null) ? $body['connections'] : [] as $i => $connection) {
        if (!is_array($connection)) {
            continue;
        }
        $check($connection, 'connection', 'connections[' . $i . '].');
        foreach (['smaily', 'intelligence'] as $block) {
            if (is_array($connection[$block] ?? null)) {
                $check($connection[$block], $block, 'connections[' . $i . '].' . $block . '.');
            }
        }
    }

    return $unknown;
}

/**
 * array_is_list() for PHP 8.0.
 *
 * @param array<mixed> $array
 */
function array_is_list_(array $array): bool
{
    return $array === [] || array_keys($array) === range(0, count($array) - 1);
}
