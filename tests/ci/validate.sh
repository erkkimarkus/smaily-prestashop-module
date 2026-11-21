#!/bin/sh
# Starts the shop from docker-compose.yaml, lets it install dist/smailyforprestashop.zip, and checks
# the result. Prints one "ok" or "FAIL" line per check and exits non-zero when any check failed.
#
#   PS_IMAGE=prestashop/prestashop-flashlight:9.2.0-nginx tests/ci/validate.sh
#
# PS_COMPOSE picks the shop image family: docker-compose.yaml (flashlight, the default) or
# docker-compose.official.yaml (the official image, which installs the shop first and takes longer).
#
# Set KEEP=1 to leave the shop running at http://localhost:8000 (admin-dev, admin@prestashop.com /
# prestashop) for a look around.
#
# CI_PORT moves the shop off port 8000, and COMPOSE_PROJECT_NAME off the Compose project "ci", so that
# two checkouts can run this script at the same time.
set -u
cd "$(dirname "$0")"
COMPOSE="docker compose -f ${PS_COMPOSE:-docker-compose.yaml}"
URL="http://localhost:${CI_PORT:-8000}"
fails=0
tmp=$(mktemp -d)

report() {
    if [ "$1" -eq 0 ]; then echo "  ok   $2"; else echo "  FAIL $2"; fails=$((fails + 1)); fi
}
sql() {
    $COMPOSE exec -T mysql mariadb -uprestashop -pprestashop -N -s -e "$1" prestashop
}
teardown() {
    if [ "${KEEP:-}" = 1 ]; then echo "* Shop left running at $URL (KEEP=1)"; else $COMPOSE down -v >/dev/null 2>&1; fi
    rm -rf "$tmp"
}

echo "* Starting ${PS_IMAGE:-the default image}"
$COMPOSE up -d --quiet-pull || { echo "docker compose up failed"; teardown; exit 1; }

echo "* Waiting for the storefront (the shop installs the module before it starts serving)"
i=0
until curl -sfo /dev/null "$URL/"; do
    status=$(docker inspect -f '{{.State.Status}}' "$($COMPOSE ps -aq prestashop)" 2>/dev/null)
    i=$((i + 1))
    if [ "$status" != "running" ] || [ $i -ge 150 ]; then
        echo "PrestaShop did not come up (container status: ${status:-gone}). Last log lines:"
        $COMPOSE logs --no-color prestashop | tail -60
        teardown
        exit 1
    fi
    sleep 5
done

echo "* Checking the installation"
expected=$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" ../../src/smailyforprestashop.php)
installed=$(sql "SELECT version FROM ps_module WHERE name='smailyforprestashop' AND active=1")
[ "$installed" = "$expected" ]; report $? "module installed and active with version $expected (got '${installed:-none}')"

expected=$(sed -n '/HOOKS = \[/,/\];/p' ../../src/src/Install/HookList.php | grep -c "^ *'")
hooks=$(sql "SELECT COUNT(DISTINCT h.name) FROM ps_hook_module hm JOIN ps_hook h ON h.id_hook = hm.id_hook JOIN ps_module m ON m.id_module = hm.id_module WHERE m.name = 'smailyforprestashop'")
[ "$hooks" = "$expected" ]; report $? "the $expected hooks of Install\\HookList are registered (got ${hooks:-none})"

expected=$(grep -c "^ *'smaily_connect_[a-z_]*' => '" ../../src/src/Install/Schema.php)
tables=$(sql "SHOW TABLES LIKE 'ps\_smaily\_connect\_%'" | wc -l | tr -d ' ')
[ "$tables" = "$expected" ]; report $? "the $expected tables of Install\\Schema exist (got $tables)"

schema=$(sql "SELECT value FROM ps_configuration WHERE name = 'SMAILY_CONNECT_SCHEMA_VERSION'")
[ -n "$schema" ]; report $? "the schema version is stored (got '${schema:-none}')"

echo "* Running the unit and integration tests inside the shop"
# The zip's vendor/ and the repository's src/vendor/ come from one composer.lock, so both declare the
# same ComposerAutoloaderInit class, and PrestaShop includes the module's autoloader when it builds a
# container. While the tests run, the module folder points at the repository's src/ (as in the dev
# shop), so only one autoloader is ever loaded; the zip's folder is put back afterwards.
module=/var/www/html/modules/smailyforprestashop
$COMPOSE exec -T prestashop sh -c "mv $module /tmp/smailyforprestashop-zip && ln -s /smaily/src $module"
$COMPOSE exec -T -u www-data -e PS_ROOT_DIR=/var/www/html -w /var/www/html prestashop \
    php $module/vendor/bin/phpunit --configuration /smaily/phpunit.xml.dist
report $? "unit and integration tests"
$COMPOSE exec -T prestashop sh -c "mv $module /tmp/smailyforprestashop-repo && mv /tmp/smailyforprestashop-zip $module"

echo "* Opening the storefront while the schema version is unset"
sql "DELETE FROM ps_configuration WHERE name = 'SMAILY_CONNECT_SCHEMA_VERSION'"
# A shop with two or more languages answers 302 at "/", and a redirect runs no fallback tick: the
# request follows it to the page.
code=$(curl -s -L -o /dev/null -w '%{http_code}' "$URL/")
[ "$code" = 200 ]; report $? "the storefront answers 200 (got $code)"
# The fallback tick of that request can finish the migration and store the version itself, after
# the response (10 seconds at most): wait for it, then put the version back only where it is still
# missing, so that the key keeps one row.
i=0
while [ $i -lt 12 ] && [ -z "$(sql "SELECT 1 FROM ps_configuration WHERE name = 'SMAILY_CONNECT_SCHEMA_VERSION' LIMIT 1")" ]; do
    sleep 1
    i=$((i + 1))
done
sql "INSERT INTO ps_configuration (name, value, date_add, date_upd) SELECT 'SMAILY_CONNECT_SCHEMA_VERSION', '$schema', NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ps_configuration WHERE name = 'SMAILY_CONNECT_SCHEMA_VERSION')"

echo "* Sending one API client request through the Symfony HTTP client this PrestaShop ships"
$COMPOSE exec -T prestashop php /tests/api-client-smoke.php
report $? "API client smoke check"

echo "* Checking the front controllers"
code=$(curl -s -o "$tmp/rss.xml" -w '%{http_code}' "$URL/index.php?fc=module&module=smailyforprestashop&controller=SmailyRssFeed")
[ "$code" = 200 ] && grep -q '<rss' "$tmp/rss.xml"; report $? "RSS feed answers 200 with an rss document (got $code)"

for controller in SmailyCustomerCron SmailyCartCron; do
    body=$(curl -s "$URL/index.php?fc=module&module=smailyforprestashop&controller=$controller&token=wrong")
    [ "$body" = 'Access denied!' ]; report $? "$controller refuses a wrong token (got '$body')"
done

if [ $fails -gt 0 ]; then
    echo "* $fails check(s) failed. Last PrestaShop log lines:"
    $COMPOSE logs --no-color prestashop | tail -80
fi
teardown
[ $fails -eq 0 ]
