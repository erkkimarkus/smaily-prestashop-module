# Local development environment

This recipe runs a local PrestaShop with the module from `src/`. It was run on 2026-11-20 on macOS
(Apple Silicon) with Docker in a Colima VM (2 CPUs, 8 GiB), Docker Compose 5.5.1, first with the 3.0.0
skeleton and the event queue, and again as the code grew, last on 2026-11-23. Each step says whether
it was verified, and on which code.

The admin user and password are the demo ones in [CONTRIBUTING.md](../CONTRIBUTING.md).

## 1. Host tools

The host needs Docker with Compose, PHP 8.1 or newer and Composer. Verified with PHP 8.5.11 and Composer
2.10.3 from Homebrew. Without PHP on the host, run Composer in a container (not verified):

    docker run --rm -v "$PWD":/app -w /app composer:2 install --working-dir src/

Install the dependencies. The dev stack mounts `src/` with its `vendor/` into the shop, so this step
comes first:

    composer install --working-dir src/

The tests use PHPUnit 9.6, the newest PHPUnit that runs on PHP 8.0. The lock holds PHP CS Fixer at
3.52.1.

## 2. Unit tests and code style on the host (verified)

    src/vendor/bin/phpunit
    (cd src && vendor/bin/php-cs-fixer fix --dry-run --using-cache=no)

On the host, PHPUnit runs the unit tests and skips the integration tests, which need a shop (section
4). The counts grow with the code; section 4 gives the last verified counts of the whole suite.
PHP CS Fixer checks `src/` only (its finder starts there), not `tests/`. Set
`PHP_CS_FIXER_IGNORE_ENV=1` on a PHP newer than PHP CS Fixer supports. PHPStan does not run: its
config and Composer script exist, but no `phpstan` binary is installed.

## 3. Pick a project name and free ports

`docker-compose.yaml` reads these variables, defaults in brackets:

| Variable | Default | Use |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | the folder name | Names the containers, volumes and network |
| `PS_IMAGE` | `prestashop/prestashop-flashlight:9.2.0-nginx` | Shop image, e.g. `prestashop/prestashop-flashlight:8.2.8-8.0-nginx` |
| `PS_PORT` | `8080` | Host port of the shop; also sets `PS_DOMAIN` |
| `PMA_PORT` | `8888` | Host port of phpMyAdmin |
| `MAILHOG_PORT` | `8025` | Host port of the MailHog UI |

Put them in a `.env` file at the repository root. Git ignores `.env`, and Compose reads it on its own.
Check that the ports are free first:

    for p in 8081 8082 8026; do lsof -nP -iTCP:$p -sTCP:LISTEN >/dev/null && echo "$p busy"; done

Example `.env`, for a machine where other projects hold 8080 and 8888:

    COMPOSE_PROJECT_NAME=smaily-presta-dev
    PS_PORT=8081
    PMA_PORT=8082
    MAILHOG_PORT=8026

## 4. Start the shop and run the tests in it (verified on PrestaShop 9.2.0)

Start only the shop and its database. phpMyAdmin and MailHog are optional and cost memory:

    docker compose up -d prestashop          # add phpmyadmin mailhog when needed

The first start pulls the image (1.5 GB for 9.2.0) and restores the demo database; the storefront
answered HTTP 200 about 2.5 minutes after the command, most of it the pull.

The shop does **not** install the module on its own. `src/` is mounted into
`modules/smailyforprestashop`, and the repository is mounted read-only at `/smaily`. Install the module
with the PrestaShop console:

    docker compose exec -u www-data prestashop php bin/console prestashop:module install smailyforprestashop

Verified result of a fresh 3.0.0 install: `Install action on module smailyforprestashop succeeded`, the
`ps_module` row at version 3.0.0, 30 hooks, the 10 `ps_smaily_connect_*` tables and the configuration key
`SMAILY_CONNECT_SCHEMA_VERSION`. A fresh install creates no `smaily_cart` table, no other `SMAILY_*`
key and no override file. The first page request afterwards runs the fallback tick and writes
`SMAILY_CONNECT_TICK_STATE`. No door has a token yet: the Dashboard's Background tasks card creates
the tick URL's token when it asks for the cron line (at once while the tasks do not run, for an
admin who may change the settings), and a request to a cron or tick URL creates none.

Run the unit and integration tests inside the shop, from the module's own folder:

    docker compose exec -u www-data -e PS_ROOT_DIR=/var/www/html prestashop \
      php modules/smailyforprestashop/vendor/bin/phpunit --configuration /smaily/phpunit.xml.dist

Last verified results, all passing: 2232 tests on 8.2.8 (11942 assertions with debug mode on,
11941 with it off) and on 9.2.0 (11947 assertions; one test checks more where PrestaShop has
discount types), with debug mode on and with debug mode off, the two runs of a version on one
shop made from scratch behind the egress guard (2026-11-23, with the simplification batch of
that day; 8.2.8 runs PHP 8.0.30 and 9.2.0 runs PHP 8.5.12). With debug mode off on 9.2.0 the run
prints deprecation notices of modules that the image ships and of PrestaShop itself (191 lines
in that run); none names the module's code or its tests. A test opens a private member through
`IntegrationTestCase::accessible()`, which calls `setAccessible()` only on PHP 8.0, where it is
needed: PHP 8.5 deprecates the call, and a unit test fails on a direct one. One
run of that day on 8.2.8 failed `ConcurrentFlushTest` alone, as one earlier run had: a worker
booted later than the 3 seconds the test gave it then, on a machine that ran other stacks. The
test now waits until its workers are ready, and passed 10 of 10 runs on 8.2.8 while the suite of
a 9.2.0 shop ran beside it. 8.0.0 last
ran with the upgrade-safe core only (278 tests, 1337 assertions). On the host the same suite runs its unit tests
and skips the integration tests (2232 tests, 3932 assertions, 1132 skipped). The storefront scripts have
their own tests on Node's built-in runner, which needs no package: `node --test tests/js/` (43 tests).
`PS_ROOT_DIR` makes the test bootstrap boot the
shop, and the integration tests then run against its database. Start PHPUnit from the module folder,
not from `/smaily/src`: PrestaShop includes the module's `vendor/autoload.php` when it builds a
container, and a second Composer autoloader from another path stops PHP with "Cannot redeclare class".

The dev shop runs with debug mode on, and a live store with it off. PrestaShop 9.2.0 reports a failed
query differently in the two (facts file 6.11): with debug mode off it answers `false` and throws
nothing. The module's own statements go through its gateway, and a test fails one of them with
`tests/Integration/Fixtures/FailingQueries.php`, which answers `false` on every lane. A test of code
that relies on a core API (the copy of a cart rule's restrictions, the load of the configuration)
fails the query for real and shows the live behaviour only with debug mode off, so the suite runs
with debug mode off too (verified on 8.2.8 and 9.2.0 with the whole suite):

    docker compose exec -u www-data prestashop sh -c \
      'echo "<?php define(\"_PS_MODE_DEV_\", false);" > /tmp/no-debug.php'
    docker compose exec -u www-data -e PS_ROOT_DIR=/var/www/html prestashop \
      php -d auto_prepend_file=/tmp/no-debug.php modules/smailyforprestashop/vendor/bin/phpunit \
      --configuration /smaily/phpunit.xml.dist

The run builds `var/cache/prod`; remove that folder afterwards.

A test that starts a PHP subprocess (the concurrency workers under `tests/Integration/`) reads the
subprocess's output. The subprocess prints with `fwrite(STDOUT, …)`, not `echo`: the shop's bootstrap
can buffer what `echo` prints, and the test then waits for a line that never comes. Workers that
must start together print a line when they have booted the shop, and the test names their start
time only then (`ConcurrentFlushTest`): a fixed number of seconds for the boot failed on a busy
machine.

The GitHub mock runs PHP's built-in server with four workers, under `setsid`, and its teardown signals
the server's whole process group. On PHP 8.0 a signal to the server alone left the four workers
running after every suite run (20 processes and 187 MB after 5 runs in the 8.2.8 shop). A shop that
ran the suite before this holds such processes until its container restarts.

The integration tests call no real outside service: the Smaily calls go to the Smaily mock
(`tests/Integration/Fixtures/SmailyMockServer.php`), and the GitHub release call goes to the GitHub
mock (`GithubMockServer.php`). A test that runs the whole tick stubs the update check. The Campaign
Intelligence calls go to the engine mock (`EngineMockServer.php` with `mock-engine/router.php`),
whose transport sends `https://intelligence.smaily.com` to the mock and lets no request to another
host leave. The tick's health check asks the engine about every stored connection, so a test that
stores a connection removes it (`tests/Integration/Engine/EngineTestCase.php` does); with an empty
connection table the check sends nothing. Each mock hands every test the same HTTP client: a
client keeps its connection open while it lives, PHPUnit keeps every test until the run ends, and
with a client per test the suite ran out of open files (the limit is 1024) near its 1740th test
on 8.2.8. Two known gaps:

- The Smaily mock's transport sends only `https://<subdomain>.sendsmaily.net` to the mock and passes
  any other host on to the real HTTP client. A test that runs the whole tick with that transport
  could reach a real outside service. Stub every other outside call in such a test.
- Some test does not restore the cron tokens it found. After a full suite run, the dev shop refuses
  the cron or tick token it had before; read the new token from the `configuration` table.

The suite expects a shop that nobody has set up by hand, and these conventions keep it so:

- Run it on a fresh or reset shop (section 8). The suite is not green on a shop that was walked by
  hand: after a walk of the admin screens that added a second shop, 27 migration tests failed on
  what the walk left.
- The abandoned-cart integration tests (`tests/Integration/Automation/AbandonedCart/`) expect a
  shop with no leftover carts; a reset shop is green.
- A storefront with more than one language can answer `/` with a 302 (flashlight 8.2.8 does; 9.2.0
  answered 200 with two languages). A check that expects 200 at `/` (step 6 of the CI script,
  section 6) holds on a shop with one language.
- A test that requests the storefront records a fresh tick first, as `RssFeedTest` does. Otherwise the
  request runs the fallback tick, and with it the update check.

One test drives the shop's own web server over HTTP (`AfterResponseSessionTest`: a login and a
registration on a request that works after its response). It reaches the web server on port 80 of the
container where PHPUnit runs, and it is skipped where that server cannot send a response early (Apache
with mod_php).

Page traffic in the dev shop runs the fallback tick, and with it the daily update check against the
real GitHub (`api.github.com`, no store data). Two tests pin the tick's full job list
(`SchedulerProviderTest`, `PendingMigrationTest`): a change that registers a scheduler job edits both.

A shop with two or more active languages may answer 302 at `/` (to `/en/`; flashlight 8.2.8 does,
9.2.0 answered 200), and a redirect runs no fallback tick (verified on 8.2.8). A walk or a script
that opens the storefront to run the tick follows redirects: `curl -L`. The module's own front controllers (the cron and tick URLs, the RSS
feed) answer without a redirect.

`composer test` does not work inside the dev shop. It runs `phpunit --configuration
../phpunit.xml.dist`, and the folder above the module in the shop is `modules/`, which holds no
`phpunit.xml.dist` (verified: `Could not read "../phpunit.xml.dist"`). It also does not set
`PS_ROOT_DIR`.

- Storefront: `http://localhost:$PS_PORT/`
- Admin: `http://localhost:$PS_PORT/admin-dev/` (PrestaShop 9 serves the login at `/admin-dev/login`)
- Database: `docker compose exec mysql mariadb -uprestashop -pprestashop prestashop`

Memory of the running pair on PrestaShop 9.2.0: shop 344 MiB, MariaDB 156 MiB, about 0.5 GiB together.
Disk: the 9.2.0 image 1.54 GB, the 8.2.8 image 1.12 GB, the 8.0.0 image 1.1 GB, the two volumes about
0.7 GB.

Code changes in `src/` show at once, as the folder is mounted. After a change to services or routes,
or after a shop or a language was added, remove the cache folders:

    docker compose exec -u www-data prestashop sh -c 'rm -rf var/cache/dev var/cache/prod'

`php bin/console cache:clear` once left the dev shop with a container that did not build, during
the Settings work; removing the folders has not failed.

## 4a. Keep a walk away from the real Smaily: the egress guard (verified on 8.2.8 and 9.2.0)

The tests use mocks, but whoever walks the admin screens types credentials, and the
shop then calls `https://<subdomain>.sendsmaily.net` for real. The egress guard in
`tests/egress-guard/` closes that door for a dev shop (the flashlight images of
`docker-compose.yaml`):

- the Smaily mock of the tests (`tests/Integration/Fixtures/mock-smaily/router.php`) runs inside the
  shop container, behind a TLS front for every `*.sendsmaily.net` name, with a certificate authority
  that the script creates and trusts in that container only;
- the Campaign Intelligence engine mock of the tests
  (`tests/Integration/Fixtures/mock-engine/router.php`) runs there too, behind a TLS front for
  `intelligence.smaily.com`, the one host the module calls the engine at (contract "Base context");
- the subscription mock (`tests/Integration/Fixtures/mock-subscription/router.php`), the receiver
  of the installation report, runs there too, behind a TLS front for `subscription.smaily.com`;
- a proxy in the container (`proxy.php`) tunnels a request for a `*.sendsmaily.net` host, for
  `intelligence.smaily.com` or for `subscription.smaily.com` to its front and refuses every other
  host;
- PHP-FPM gets `https_proxy` and `http_proxy`, so every request that a page makes goes to the proxy.

Start it from the repository root, with the stack's Compose variables set as for `docker compose`
(section 3). `MOCK_USER` and `MOCK_PASSWORD` are the only credentials the mock accepts; invent them:

    MOCK_USER=walk-user MOCK_PASSWORD=not-a-real-password tests/egress-guard/install.sh

**Load the guard before the first request to the module: before the first admin page and before
the first tick.** A shop that holds an account, also the invented account of a legacy fixture, calls
that account's `*.sendsmaily.net` host from the tick (the reconcile, the imports, the queue flush),
and a page request or a cron URL runs the tick. A walk once reached the real Smaily
host with invented credentials, because its proxy settings sat in a file that PHP-FPM never read: the
flashlight `php-fpm.conf` does not include `php-fpm.d/`. `install.sh` therefore appends the settings
to `php-fpm.conf` itself, reloads PHP-FPM, and ends with the check below. Recreating the shop
container removes the guard (section 8); install it again.

Check that it is active, at any time:

    tests/egress-guard/check.sh        # prints "guard active", or what is missing and exits 1

The check asks five things: a page request runs with the proxy setting, a Smaily host reaches the
mock, the engine host reaches the engine mock, the installation report's host reaches the
subscription mock, and another host is refused. The proxy writes each decision, host and verdict only, to
`/tmp/smaily-egress.log` in the container:

    docker compose exec -u www-data prestashop sh -c 'cut -d" " -f2- /tmp/smaily-egress.log | sort | uniq -c'

`check.sh`, and `install.sh`, which ends with it, leave one request in the engine mock's state: a
`GET /api/v1/ingest/ping` by curl, without a key. A walk that asserts "the engine was not called"
discounts it. The engine mock rewrites its state file on every request: a script that reads the
file in the middle of a write gets cut JSON and reads again.

`TUNNEL <host> -> mock` is a Smaily call that the mock answered; `TUNNEL intelligence.smaily.com ->
engine mock` is a Campaign Intelligence call that the engine mock answered; `REFUSED CONNECT <host>`
is a call that did not leave the container (PrestaShop's own calls and the module's update check
show here).

The engine mock's state is `/tmp/smaily-mock/engine-state.json` in the container: the connections
by the API key it issued (never copy a key into a report), the used setup tokens, every request it
received, and its switches, which `mock-engine/router.php` lists (`smaily_subdomain`, `tenant_id`,
`next_prefix`, `prefix_answer`, `require_capability`, `tenant_inactive`, `fail`, `delay`). It
also answers the three privacy routes (contract §8, §9 and §10): it knows every address but one
that `unknown_customers` lists or that a deletion put into `deleted_customers` (both answer
HTTP 404, one list for every engine store), keeps each address's choice under `opt_outs`, and
takes `fail` and `delay` under the names `customer_export`, `customer_delete` and
`customer_opt_out`. It answers the two storefront routes too (contract §6 and §15): the browse
events, each validated on its own, and a shopper's recommendations, which a walk gives under
`recommendations` as `{"customer:<id>": [{"external_id": "6"}, …]}` or `{"token:<visitor token>":
[…]}` (a shopper without an entry gets the empty answer); `visitor_tokens` lists the tokens that
count as bound to a customer; their names for `fail` and `delay` are `browse` and
`recommendations`. It answers the ingest routes too (`mock-engine/ingest.php`, written from the
contract's text: §3 catalog, §3b removal, §3c nightly list, §4 customers, §5 orders, §7 identity
merge), checks each request against the contract's required fields, limits and key prefix, and
answers the contract's errors (per-item `errors[]`, `validation_failed`,
`connection_prefix_mismatch`, `customer_not_found`) and the nightly list's truncation guard. What
it took is in the state under `catalog`, `customers`, `orders`,
`manifests`, `merges`, `bound_tokens` and `event_ids`, per engine store; `fail` and `delay` take
the names `catalog`, `catalog_remove`, `catalog_manifest`, `customers`, `orders` and
`identity_merge`. It answers the history import's progress too (contract §25): `history` holds
each connection's import with its phase and stop reason, `history_calls` every call.
It answers the automation routes too (`mock-engine/automations.php`, written
from the contract's text: §11 catalogue, §12 configuration, §13 save with its all-or-nothing
check and its `errors[]`). `automations` lists the emails the engine offers (a walk changes it
between two page loads; without it the mock offers two), `automation_configs` holds the saved
rows per engine store (a walk writes a row there to play another connector or a Smaily
operator), `automations_live` lists per engine store the emails whose real sends are on (a save
that asks for real sends keeps test mode for every other email, as the engine does), and `fail`
and `delay` take the names `automations_catalog`, `automations_config` and `automations_save`.
The card of Settings > Automations is asked of the engine after the page has loaded: a walk
waits for it before it reads the card. Any token
that the mock has not seen works once as a setup link
(`https://intelligence.smaily.com/setup/<token>`); a token that starts with `notfound` answers 404,
one listed under `used_tokens` answers 410, and with `smaily_subdomain` set to another account the
module refuses the link. A walk connects a shop on Settings > Intelligence or on step 4 of the
Initial setup. The three imports then run with the tick, one after another: a walk that does not
wait a minute between two ticks removes `engine_import` and `ingest_flush` from the `jobs` of
`SMAILY_CONNECT_TICK_STATE` before each tick. The health check runs with the tick too. `install.sh` keeps an engine state that exists, so a shop's
stored connections stay known to the mock when the guard is installed again.

**The installation report** (ARCHITECTURE 11.1). `docker-compose.yaml` and the two Compose files of
`tests/ci/` resolve `subscription.smaily.com` to 127.0.0.1 inside the shop container
(`extra_hosts`), so a shop without the guard gets no answer there and never reaches the real
receiver; a report then waits on its retries and leaves a line in the shop's log. A shop container
made before that setting is recreated (section 8: the demo database comes back), and
`tests/bootstrap.php` refuses to run the suite in a shop where the name resolves elsewhere. Behind
the guard the subscription mock is the receiver. Its state is
`/tmp/smaily-mock/subscription-state.json` in the container: under `requests` every report it got
(the time, the answered status, the body, the contract rules the body broke, the fields the
contract does not name, and whether the credentials were the ones the Smaily mock accepts; never
the credentials), and the switches `fail` (`{"status": 503, "error": "unavailable",
"retry_after": 60}`: every accepted report gets that answer) and `delay` (seconds before an
answer; the module gives up after 3 to 5). The report goes five minutes after a save and once a
day: a walk moves the stored moment instead of waiting, for example

    UPDATE ps_configuration SET value = JSON_SET(value, '$.setup_saved_at', UNIX_TIMESTAMP() - 1)
      WHERE name = 'SMAILY_CONNECT_REPORT_STATE';

(`$.daily_at` and `$.retry.at` the same way), and then runs the tick over its URL. The proxy's log
shows the order of an uninstall: `TUNNEL subscription.smaily.com -> subscription mock`, then
`TUNNEL intelligence.smaily.com -> engine mock`.

The guard covers page requests. A command that you run with `docker compose exec` (the console,
PHPUnit) reads its own environment: give it the proxy with
`-e https_proxy=http://127.0.0.1:3128 -e http_proxy=http://127.0.0.1:3128 -e no_proxy=localhost,127.0.0.1`.
The mock's state (the requests it received, the workflows it lists) is `/tmp/smaily-mock/state.json`
in the container. `install.sh` seeds one workflow in it (id 11, "Welcome", a "form submitted"
workflow that is switched on); a walk that needs more workflows, or one per account, edits the
`workflows` list of that file. Each recorded request names its `host`, so a walk can tell which Smaily account
a call went to; a workflow of the state with a `subdomain` is listed only to that account. The
mock keeps no credential that a request brought, because whoever walks the shop may type a real
API user and password into it: a request names its API user (`username`) only when the mock
accepted it, and of any other request the state says only whether an Authorization header came
(`authorization`) and how long the user's name was (`username_length`); the password and the
header are never written. The typed subdomain stays, as the request's `host`. The
console's `prestashop:module upgrade` asks PrestaShop's Distribution API first and fails behind the
guard (seen on 8.2.8): a walk that stages an upgrade lets the first tick run the pending migration. `"contact_writes_fail": true` in it makes the mock answer HTTP 500 to every contact
write, so a contact row fails and waits on the retry ladder; `"contact_reads_fail"` does the same for
a single-contact read, and `"automation_posts_fail"` for every automation email (`POST autoresponder.php`), so an automation row fails and waits for its retry. The guard is not installed in the shop of `tests/ci/validate.sh`; a walk in that shop (`KEEP=1`) installs it with `DC="docker compose -f tests/ci/docker-compose.yaml"` and the script's `COMPOSE_PROJECT_NAME` and `CI_PORT` (verified on 8.2.8 and 9.2.0).

In a shop with debug mode on (the dev stack), `prestashop:module upgrade` stops behind the guard with
`Unable to retrieve informations from Distribution API`: a native module asks
`api.prestashop-project.org` during the upgrade, the proxy refuses the call, and debug mode turns that
error into a failure of the command. With debug mode off for that one command, PrestaShop goes on
without the answer and the upgrade runs (verified on 8.2.8):

    docker compose exec -u www-data -e https_proxy=http://127.0.0.1:3128 \
      -e http_proxy=http://127.0.0.1:3128 -e no_proxy=localhost,127.0.0.1 prestashop sh -c \
      'echo "<?php define(\"_PS_MODE_DEV_\", false);" > /tmp/no-debug.php \
       && php -d auto_prepend_file=/tmp/no-debug.php bin/console prestashop:module upgrade smailyforprestashop'

The command builds `var/cache/prod`; remove that folder afterwards. A shop that runs with debug mode
off needs no such step: 22 console upgrades from 2.2.0 ran behind the guard in one.

## 4b. Walking the storefront: the consent gate, the block and the cache (verified on 8.2.8 and 9.2.0)

Nothing of the storefront runs before the shop has a connection (section 4a). The merchant confirms
the consent setting on Settings > Intelligence ("My store's cookie banner covers Smaily tracking",
then Save), and a walk does the same. The block shows from the page after the landing on: the
landing stores the visitor token, and the next page asks for the recommendations with it.

**Probe the consent gate in a real browser, in both states.** A gate that fails closed looks the
same as a tracker that works and has nothing to send. On any storefront page, in the console:

    window.smailyConnect.consentGiven()                    // the rule's answer for this visitor
    window.smailyConnectStorefront.consentConfirmed        // the merchant's setting, as the page got it
    typeof window.smailyConnect.consentOverride            // "function" when the store defines one
    await cookieStore.getAll()                             // no smaily_anon_sid without consent

and in the network log: without consent no request to `…/module/smailyforprestashop/relay` or
`…/recommendations`; with consent a `relay` request about 5 seconds after a product, category or
search page, or when the page is left. The engine mock's state lists what reached the engine. A
store's own consent adapter is one line in the theme, for example
`window.smailyConnect = window.smailyConnect || {}; window.smailyConnect.consentOverride = function () { return false; };`
before the module's scripts.

**The block.** Put the module on a display hook in Design > Positions ("Transplant a module" on
PrestaShop 8, "Hook a module" on 9), or write `{widget name='smailyforprestashop'}` into a theme
template. On PrestaShop 9 the form's fields are `hook_module[id_module]` and
`hook_module[id_hook]` (8: `id_module`, `id_hook`), its Save button has no `type` attribute, and
the hook list fills after the module is chosen. The flashlight shops never recompile a changed template (`PS_SMARTY_FORCE_COMPILE` is
`0`): set it to `1` in the `configuration` table, or clear the cache in Advanced Parameters >
Performance, before a changed `.tpl` shows. A visitor gets products only with a visitor token or a
login: open a page with `?smaily_vt=vt_<10 characters>` first, and give the mock recommendations
for that token.

**The cache.** The recommendations and the per-address limits are small files in
`var/cache/<environment>/smaily_connect/`. An answer of the engine, an empty one too, stays for an
hour: a walk that changes the mock's recommendations for a shopper who was already asked about
uses another token or customer, or clears PrestaShop's cache. A limit's count ends with its minute.

**The checkout.** The flashlight shops offer their payment modules to one country only (France);
a walk that orders from another country adds that country in Payment > Preferences first. On
8.2.8 the address form starts with the country of the browser's language: a headless browser
with a US English locale gets the United States and then no payment option, so a script gives
its storefront context a French locale (seen on 2026-11-23; 9.2.0 started with France either
way).

**A privacy request through psgdpr** (walked on 2026-11-23). Its admin screen is Module Manager >
psgdpr > Configure > Personal data management. Every key press in its search field starts a
request, and a late answer redraws the blocks and empties an open one: a script waits until those
requests are quiet before it clicks a block. On 9.2.0 that search and the data view fail in
psgdpr itself (facts file 6.13); walk the customer's own export page and the address's block
there. The customer's CSV file is UTF-16. The console's `prestashop:module install psgdpr`
enables it in the default shop only. A cart that a walk leaves open is picked up by the
abandoned-cart job after its delay, so "this shopper's rows did not change" is compared row by
row, not by count. The Log's "Privacy request" form is shown beside psgdpr too. A back-office page
that is opened more than 60 seconds after the last tick runs the fallback tick, which sends the
queued rows: a walk that checks what one request sent runs the tick over its URL just before.
On a machine that runs other stacks, the first back-office page after a cleared cache took over a
minute on 8.2.8: a script opens a page to build the cache before its timed steps.

**A walk over HTTP** (`curl` with a cookie jar; verified in shops with one language). With debug
mode on, a page asked for as `index.php?controller=order` answers "[Debug] This page has moved"
with HTTP 200 and runs no page hook: ask for the friendly address (`/order`,
`/cart?action=show`). The landing endpoint takes a form post (`rec_id`, `context`,
`visitor_token`), a sign-in is a post of `email`, `password` and `submitLogin=1` to the
authentication controller, and the customer step of a guest checkout a post of the form's fields
with `submitCreate=1` to `/order`. The address step's form carries a `token` of its own, which is
not the page's `static_token`; the delivery step is a post of `delivery_option[<address id>]` with
`confirmDeliveryOption=1`, and a post to `/module/ps_wirepayment/validation` places the order. The
ingest flush runs once in 60 seconds: wait that long before the tick that should send the order.

**An order that a payment provider's server confirms.** No shipped payment module validates an
order without the shopper's session. A walk copies a throwaway payment module into the container
(never into the repository) whose front controller calls `validateOrder()` for a cart id and the
customer's `secure_key`, as a provider's webhook does, and asks for it without a cookie; the same
address asked for with the shopper's cookie jar stands for an order in the shopper's own browser.

## 5. PrestaShop 8

Set `PS_IMAGE=prestashop/prestashop-flashlight:8.2.8-8.0-nginx` (PrestaShop 8.2.8 on PHP 8.0) or
`prestashop/prestashop-flashlight:8.0.0-8.0-nginx` (8.0.0 on PHP 8.0) in `.env`, and use a different
`COMPOSE_PROJECT_NAME`, or reset the environment first: the volumes keep the files and the database of
the image they were created with.

Verified on 8.2.8 and 8.0.0 through the CI script (section 6). The dev stack itself was verified on
9.2.0 only.

One series of PHPUnit runs in the 8.2.8 shop (PHP 8.0.30) stopped with `Segmentation fault` and passed
with `-d zend.enable_gc=0`. The cause is not known. Afterwards more than 100 runs of the suite in that
image passed without the switch, plain and with `--debug`, at five commits, and three runs under
Valgrind showed no invalid write or free; the command of section 4 therefore carries no GC switch. If
PHP stops that way again, keep the evidence before anything else changes. The image sets no core
limit, so PHP leaves the file `core` in the folder the command ran in (`/var/www/html/core`), and gdb
names the functions on the stack (`zend_gc_collect_cycles` is the garbage collector):

    docker compose exec -u root prestashop apk add --no-cache gdb
    docker compose exec prestashop gdb /usr/local/bin/php core -batch -ex bt

Keep the exact command and the working tree, as a commit, with the backtrace. A page request that
crashes shows in `/var/log/php/php-fpm-errors.log` as `exited on signal 11 (SIGSEGV)`.

## 6. CI validation script (verified)

    composer --working-dir src package       # builds dist/smailyforprestashop.zip
    composer install --working-dir src       # the tests in the shop use the development dependencies
    PS_IMAGE=prestashop/prestashop-flashlight:8.2.8-8.0-nginx tests/ci/validate.sh
    PS_IMAGE=prestashop/prestashop-flashlight:9.2.0-nginx tests/ci/validate.sh

`validate.sh` starts a throwaway shop that installs `dist/smailyforprestashop.zip` (no development
dependencies), and prints one `ok` or `FAIL` line per check, 10 lines in all:

1. The module is installed and active with the version of the main class.
2. Every hook of `Install\HookList` is registered.
3. Every table of `Install\Schema` exists.
4. The schema version is stored.
5. The unit and integration tests pass inside the shop. For this step the module folder points at the
   repository's `src/`, as in the dev shop, so that one Composer autoloader is loaded; the zip's folder
   is put back afterwards. The script's shop runs with debug mode off, the flashlight default (the
   dev stack sets `DEBUG_MODE=true`): this check is the suite with debug mode off.
6. The storefront answers 200 while the schema version is unset.
7. The API client sends one request through the HTTP client of this PrestaShop, to a mock transport,
   not to Smaily (`tests/ci/api-client-smoke.php`). The script loads PrestaShop's autoloader from
   `PS_VENDOR_AUTOLOAD` and then the module's from `MODULE_AUTOLOAD`; each defaults to its path in the
   shop container.
8. The RSS feed answers 200 with an RSS document.
9. The two cron controllers refuse a wrong token.

Results on 2026-11-20:

- With the skeleton and the event queue: all 10 checks `ok` on flashlight 8.0.0, 8.2.8 and 9.2.0, and
  on the official image `prestashop/prestashop:9.2.0-8.1` with `PS_COMPOSE=docker-compose.official.yaml`
  (the image CI uses for 9.2.0).
- With the upgrade-safe core: the in-shop tests of that time passed on flashlight 8.0.0, 8.2.8 and
  9.2.0 (8.0.0: 278 tests, 1337 assertions).
- On 2026-11-21, with the admin screens, the three store automations and their discount codes on
  `v3`: 10 of 10 checks on flashlight 8.2.8 and 9.2.0 with the packaged zip, and 1415 tests in the
  shop on each (7251 assertions on 8.2.8, 7257 on 9.2.0).
- On 2026-11-21, with the database gateway: 10 of 10 checks on flashlight 8.2.8 and 9.2.0 with the
  packaged zip, and 1482 tests in the shop on each (7772 assertions on 8.2.8, 7778 on 9.2.0).
- On 2026-11-21, with the upgrade's file deletion and the tracker's retention: 10 of 10 checks on
  flashlight 8.2.8 and 9.2.0 with the packaged zip, and 1513 tests in the shop on each (8146
  assertions on 8.2.8, 8151 on 9.2.0).
- On 2026-11-21, with the engine connection of Story 5.1: 10 of 10 checks on flashlight 8.2.8 and
  9.2.0 with the packaged zip, and 1669 tests in the shop on each (8667 assertions on 8.2.8, 8672
  on 9.2.0).
- On 2026-11-21, with the privacy requests of Story 5.6: 10 of 10 checks on flashlight 8.2.8 and
  9.2.0 with the packaged zip, and 1703 tests in the shop on each (9127 assertions on 8.2.8, 9132
  on 9.2.0).
- On 2026-11-21, with the storefront of Story 5.5: 10 of 10 checks on flashlight 8.2.8 and 9.2.0
  with the packaged zip, and 1849 tests in the shop on each (9877 assertions on 8.2.8, 9882 on
  9.2.0).
- On 2026-11-21, with the ingest of Story 5.2: 10 of 10 checks on flashlight 8.2.8 and 9.2.0
  with the packaged zip, and 1957 tests in the shop on each (10518 assertions on 8.2.8, 10523 on
  9.2.0).
- On 2026-11-23, on the merged `v3` with Campaign Intelligence, the installation report and the
  fixes of the install check: 10 of 10 checks on flashlight 8.2.8 and 9.2.0 with the packaged zip,
  and 2224 tests in the shop on each (11900 assertions on 8.2.8, 11905 on 9.2.0).
- On 2026-11-23, with `api.github.com` resolved inside the shop and the fixes of that day up
  to the Log's retry checkbox: 10 of 10 checks on flashlight 8.2.8 and 9.2.0 with the packaged
  zip, and 2230 tests in the shop on each (11922 assertions on 8.2.8, 11927 on 9.2.0).
- The official image did not run with any code after the event queue.

The storefront check (step 6) deletes the schema version and opens the storefront, following a
redirect: a shop with two languages answers 302 at `/`, and a redirect runs no tick. The fallback tick
of that request finds nothing to migrate on the fresh install and stores the version again; it writes
nothing else (ARCHITECTURE section 3.7). The script then restores the version only when no row holds
it, so the shop keeps exactly one schema-version row.

The script uses the Compose project `ci` on port 8000. Set `COMPOSE_PROJECT_NAME` and `CI_PORT` to run
it beside another stack, for example `COMPOSE_PROJECT_NAME=smaily-presta-ci2 CI_PORT=8090`. It removes
its containers, volumes and network at the end; `KEEP=1` leaves the shop up.

The script's shop is not behind the egress guard. A fresh install holds no account and no
connection, so it calls no Smaily service. Its storefront request runs the fallback tick, and
with it the update check, which asks `api.github.com` for the newest release: the two Compose
files of `tests/ci/` resolve that name to 127.0.0.1 inside the shop container (`extra_hosts`),
so the check gets no answer and the script never reaches the real GitHub.

## 7. Installing or upgrading from a built zip

The dev stack mounts `src/`, so do not unzip a release into its `modules/smailyforprestashop`: that
would write into your `src/`. Use the CI shop instead, which installs `dist/smailyforprestashop.zip`
on start:

    composer --working-dir src package
    KEEP=1 tests/ci/validate.sh                       # shop stays up on http://localhost:8000

Verified on 2026-11-23 on 8.2.8 and 9.2.0, each in a shop without the `src/` mount, made from
scratch behind the egress guard: the zip, uploaded in Module Manager > Upload a module, installs
the module (6 tabs, 30 hooks, the 10 tables, the schema version key, the zip's 443 files outside
`vendor/`), and its pages open at once, without a cache removal. After a connection through the
Initial setup (a Smaily account and Campaign Intelligence, against the mocks) and one request to
the tick URL, a console uninstall told the subscription mock `deactivated` and then the engine
mock the disconnect, and left no key, table, tab, hook registration, authorization role or cache
folder of the module; a console install worked afterwards.

To upgrade that shop to a newer build (not verified): build the new zip, then

    docker compose -f tests/ci/docker-compose.yaml exec prestashop sh -c \
      'rm -rf modules/smailyforprestashop && unzip -qq /ps-modules/smailyforprestashop.zip -d modules \
       && chown -R www-data: modules/smailyforprestashop \
       && su www-data -s /bin/sh -c "php bin/console prestashop:module upgrade smailyforprestashop"'

`/ps-modules` is the read-only mount of `dist/`. The admin's Module Manager > Upload a module also takes
the zip.

To start from a legacy state, install the matching release and load a fixture from
`tests/fixtures/legacy/` (see its README). Section 7a gives the whole walk.

## 7a. Upgrade walk from a genuine 2.x or 1.6.x release

The first upgrade walks ran this way on 8.0.0, 8.2.8 and 9.2.0 (ARCHITECTURE section 3.7). The
commands below follow those walks. Steps 1 to 6 ran again on 2026-11-23 on 8.2.8 and 9.2.0, with
a 2.2.0 that the console installed in a shop without the module and the upload of step 5: the
migration ended `done`.

1. **Build the 3.0.0 zip:** `composer --working-dir src package`. The script works in
   `build/package/` and replaces `dist/smailyforprestashop.zip`; it leaves everything else under
   `build/` and `dist/` alone.
2. **Build the genuine legacy zip from its git tag** under `build/` (git ignores it), as the 2.x
   release workflow built it: the tag's `src/` renamed to `smailyforprestashop/`, Composer's production
   dependencies installed, and `.git*`, `.php-cs-fixer.dist.php` and `phpstan.neon` left out.

       mkdir -p build/legacy
       git archive --prefix=smailyforprestashop/ 2.2.0:src | tar -x -C build/legacy
       composer install --working-dir build/legacy/smailyforprestashop --no-dev
       (cd build/legacy && zip -qr smailyforprestashop-2.2.0.zip smailyforprestashop \
         -x '*/.git*' '*/.php-cs-fixer.dist.php' '*/phpstan.neon')

   1.6.2 has no Composer step. 1.6.2 does not install with debug mode on (the flashlight default);
   part 2 of `1.6.2-configured.sql` supplies its version, tab and hooks instead (fixture README).
   With debug mode off for the install command (the prepend file of section 4a), 1.6.2's own
   installer runs to the end (verified on 8.2.8 and 9.2.0, 2026-11-23).
3. **Install the legacy release** in a shop whose module folder is not the mounted `src/` (the CI shop
   with `KEEP=1`, section 7): uninstall the module, unpack the legacy zip into `modules/`, and run
   `prestashop:module install smailyforprestashop`.
4. **Load a fixture** from `tests/fixtures/legacy/` with `mariadb` (fixture README).
5. **Upgrade to 3.0.0** by one of these paths:
   - Module Manager > Upload a module with `dist/smailyforprestashop.zip`. On the flashlight 8.0.0 and
     8.2.8 images the upload fails until the web server can write its upload folder:
     `docker compose exec -u root prestashop chown www-data /tmp/client_body` (use the CI Compose file
     and project for the CI shop). Behind the egress guard the upload works with debug mode on
     (verified in a browser on 8.2.8 and 9.2.0, 2026-11-23, over 2.2.0 and over 1.6.2), where the
     console upgrade needs debug mode off (section 4a).
   - A scripted upload: log in to the admin, then post the zip to
     `/admin-dev/index.php/improve/modules/import` with the session.
   - The console: replace the module folder with the 3.0.0 files (section 7) and run
     `prestashop:module upgrade smailyforprestashop`. To leave the files of the legacy release
     on disk, as an upload does, unpack the zip over the folder instead of replacing it
     (`unzip -o -qq /ps-modules/smailyforprestashop.zip -d modules`, then `chown` and remove
     `var/cache/prod` and `var/cache/dev`): the walks of 2026-11-21 ran this way (ARCHITECTURE
     section 3.7).
   - An FTP copy: replace the files only. Nothing runs until a cron URL request or a page request
     runs the tick (ARCHITECTURE section 3.7).
6. **Check** `SMAILY_CONNECT_MIGRATION` (`status` `done`), `SMAILY_CONNECT_SCHEMA_VERSION`, the
   account, mapping and cart rows, and that the legacy keys except the two tokens are gone.

Seen in the walks of 2026-11-23 (8.2.8 and 9.2.0), on a machine that ran two other stacks:

- The flashlight web server gives a page 30 seconds (`fastcgi_read_timeout` in
  `/etc/nginx/nginx.conf`), and the first back-office pages after an upgrade took longer: they
  answered 504. A walk shop raises the two timeouts and reloads nginx, and its scripts wait
  minutes, not seconds, for a back-office page.
- With debug mode on, a PHP error of a front controller comes with HTTP 200 and the error's text
  (2.2.0's cart cron on a field list that is not JSON): judge such a request by its text.
- A shopper who signs in from another browser gets a new cart (the demo data does not switch
  "Re-display cart at login" on). A walk that changes a cart again keeps the shopper's cookies.
- On 9.2.0 with debug mode on, a page's own request ("Test connection" on the Initial setup)
  once took over a minute, while PHP-FPM's log said that all of its 5 workers were busy. A script
  waits minutes for such an answer too.
- A script that holds the migration's lock from a `mariadb` session ends that session with
  `KILL <id>`: stopping `docker compose exec` leaves it running in the container.

## 8. Stopping, restarting and resetting

    docker compose stop          # keeps containers, volumes and the database
    docker compose start
    docker compose down          # removes containers, keeps volumes
    docker compose down -v       # removes volumes too: full reset

Flashlight restores its demo database on the first start of every **new** container: its start script
keeps the "already done" markers in the container's `/tmp`. So `down` followed by `up`, or any change
that makes Compose recreate the shop container (image, ports, environment), resets the database to the
demo dump even though the volume survives; the module is then no longer installed. `stop`/`start` keep
the database. This follows from `/run.sh` in the 9.2.0 image and was not tried.

## 9. Several git worktrees at once (verified)

The Compose files set no fixed container names, so stacks with different project names and ports run
side by side (verified: the runs of section 6 ran beside another worktree's CI stack). Give each worktree its own `.env` with its own `COMPOSE_PROJECT_NAME` and ports. Compose
then keeps separate containers, volumes and networks per worktree, and each shop mounts the `src/` of
its own worktree:

    COMPOSE_PROJECT_NAME=smaily-presta-<worktree>
    PS_PORT=8181
    PMA_PORT=8182
    MAILHOG_PORT=8126

The same holds for `tests/ci/validate.sh` with `COMPOSE_PROJECT_NAME` and `CI_PORT` (section 6).

**Two shops in one browser.** A browser keeps one set of cookies per host name, whatever the port,
so two dev shops that are both opened at `localhost` share their cookies: a sign-in to the back
office of one signs the employee out of the other. Open the second shop at `127.0.0.1` and give
it that domain, with a second Compose file that changes only the shop's domain (the two test
shops of 2026-11-23 ran this way, one at `localhost` and one at `127.0.0.1`):

    # second.yaml:  services: {prestashop: {environment: ["PS_DOMAIN=127.0.0.1:${PS_PORT:-8080}"]}}
    docker compose -f docker-compose.yaml -f second.yaml up -d prestashop

Every later `docker compose` call of that stack names the same two files.

Run one shop at a time when memory is short: one shop with its database takes about 0.5 GiB.

**Colima** cannot bind-mount a path under `/private/tmp` (macOS's `/tmp`) into a container. Keep every
checkout that a shop mounts under the home directory.

## 10. CI on GitHub

`.github/workflows/validate.yaml` runs on pull requests and on pushes to `master` and `v3`: PHP syntax
on 8.0 and 8.5, PHP CS Fixer, PHPUnit on the host, and `tests/ci/validate.sh` on 8.2.8 (flashlight,
PHP 8.0) and 9.2.0 (official image, PHP 8.1). No workflow run exists on the fork yet: GitHub keeps a
fork's workflows off until the owner enables them once in the repository's Actions tab. Until then, CI
on `v3` is unverified, and the local `validate.sh` runs of section 6 are the evidence.

## Disk

A full Docker disk breaks every container that writes, also other projects' databases. The first
verified run filled a 30 GiB Colima disk; the disk is now larger. Check before a pull:

    colima ssh -- df -h /var/lib/docker
    docker system df
