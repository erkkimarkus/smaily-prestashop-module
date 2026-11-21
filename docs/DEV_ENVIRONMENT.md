# Local development environment

This recipe runs a local PrestaShop with the module from `src/`. It was run on 2026-11-20 on macOS
(Apple Silicon) with Docker in a Colima VM (2 CPUs, 8 GiB), Docker Compose 5.5.1, first with the 3.0.0
skeleton and the event queue, and again as the code grew, last on 2026-11-21. Each step says whether
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
`ps_module` row at version 3.0.0, 32 hooks, the 9 `ps_smaily_connect_*` tables and the configuration key
`SMAILY_CONNECT_SCHEMA_VERSION`. A fresh install creates no `smaily_cart` table, no other `SMAILY_*`
key and no override file. The first page request afterwards runs the fallback tick and writes
`SMAILY_CONNECT_TICK_STATE`. No door has a token yet: the Dashboard's "Show the cron line" creates
the tick URL's token, and a request to a cron or tick URL creates none.

Run the unit and integration tests inside the shop, from the module's own folder:

    docker compose exec -u www-data -e PS_ROOT_DIR=/var/www/html prestashop \
      php modules/smailyforprestashop/vendor/bin/phpunit --configuration /smaily/phpunit.xml.dist

Last verified results, all passing: 1415 tests on 8.2.8 (7251 assertions) and on 9.2.0 (7257
assertions; one test checks more where PrestaShop has discount types), each on a reset shop behind
the egress guard (2026-11-21, with the corrections to the discount codes; three runs in a row on
8.2.8, which runs PHP 8.0.30, and one on 9.2.0, which runs PHP 8.5.12, with no crash); 8.0.0 last
ran with the upgrade-safe core only (278 tests, 1337 assertions). On the host the same suite runs its unit tests
and skips the integration tests (1415 tests, 2121 assertions, 740 skipped).
`PS_ROOT_DIR` makes the test bootstrap boot the
shop, and the integration tests then run against its database. Start PHPUnit from the module folder,
not from `/smaily/src`: PrestaShop includes the module's `vendor/autoload.php` when it builds a
container, and a second Composer autoloader from another path stops PHP with "Cannot redeclare class".

The dev shop runs with debug mode on, and a live store with it off. PrestaShop 9.2.0 reports a failed
query differently in the two (facts file 6.11), so a test of code that must notice a failed read runs
with debug mode off too (verified on 8.2.8 and 9.2.0 with the discount tests):

    docker compose exec -u www-data prestashop sh -c \
      'echo "<?php define(\"_PS_MODE_DEV_\", false);" > /tmp/no-debug.php'
    docker compose exec -u www-data -e PS_ROOT_DIR=/var/www/html prestashop \
      php -d auto_prepend_file=/tmp/no-debug.php modules/smailyforprestashop/vendor/bin/phpunit \
      --configuration /smaily/phpunit.xml.dist --filter Discount

The run builds `var/cache/prod`; remove that folder afterwards.

A test that starts a PHP subprocess (the concurrency workers under `tests/Integration/`) reads the
subprocess's output. The subprocess prints with `fwrite(STDOUT, …)`, not `echo`: the shop's bootstrap
can buffer what `echo` prints, and the test then waits for a line that never comes.

The GitHub mock runs PHP's built-in server with four workers, under `setsid`, and its teardown signals
the server's whole process group. On PHP 8.0 a signal to the server alone left the four workers
running after every suite run (20 processes and 187 MB after 5 runs in the 8.2.8 shop). A shop that
ran the suite before this holds such processes until its container restarts.

The integration tests call no real outside service: the Smaily calls go to the Smaily mock
(`tests/Integration/Fixtures/SmailyMockServer.php`), and the GitHub release call goes to the GitHub
mock (`GithubMockServer.php`). A test that runs the whole tick stubs the update check. Two known
gaps:

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
- a proxy in the container (`proxy.php`) tunnels a request for a `*.sendsmaily.net` host to that
  front and refuses every other host;
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

The check asks three things: a page request runs with the proxy setting, a Smaily host reaches the
mock, and another host is refused. The proxy writes each decision, host and verdict only, to
`/tmp/smaily-egress.log` in the container:

    docker compose exec -u www-data prestashop sh -c 'cut -d" " -f2- /tmp/smaily-egress.log | sort | uniq -c'

`TUNNEL <host> -> mock` is a Smaily call that the mock answered; `REFUSED CONNECT <host>` is a call
that did not leave the container (PrestaShop's own calls and the module's update check show here).

The guard covers page requests. A command that you run with `docker compose exec` (the console,
PHPUnit) reads its own environment: give it the proxy with
`-e https_proxy=http://127.0.0.1:3128 -e http_proxy=http://127.0.0.1:3128 -e no_proxy=localhost,127.0.0.1`.
The mock's state (the requests it received, the workflows it lists) is `/tmp/smaily-mock/state.json`
in the container. `install.sh` seeds one workflow in it (id 11, "Welcome", a "form submitted"
workflow that is switched on); a walk that needs more workflows, or one per account, edits the
`workflows` list of that file. Each recorded request names its `host`, so a walk can tell which Smaily account
a call went to; a workflow of the state with a `subdomain` is listed only to that account. The
console's `prestashop:module upgrade` asks PrestaShop's Distribution API first and fails behind the
guard (seen on 8.2.8): a walk that stages an upgrade lets the first tick run the pending migration. `"contact_writes_fail": true` in it makes the mock answer HTTP 500 to every contact
write, so a contact row fails and waits on the retry ladder; `"contact_reads_fail"` does the same for
a single-contact read, and `"automation_posts_fail"` for every automation email (`POST autoresponder.php`), so an automation row fails and waits for its retry. The guard is not installed in the shop of `tests/ci/validate.sh`.

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
   is put back afterwards.
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
- The official image did not run with any code after the event queue.

The storefront check (step 6) deletes the schema version and opens the storefront, following a
redirect: a shop with two languages answers 302 at `/`, and a redirect runs no tick. The fallback tick
of that request finds nothing to migrate on the fresh install and stores the version again; it writes
nothing else (ARCHITECTURE section 3.7). The script then restores the version only when no row holds
it, so the shop keeps exactly one schema-version row.

The script uses the Compose project `ci` on port 8000. Set `COMPOSE_PROJECT_NAME` and `CI_PORT` to run
it beside another stack, for example `COMPOSE_PROJECT_NAME=smaily-presta-ci2 CI_PORT=8090`. It removes
its containers, volumes and network at the end; `KEEP=1` leaves the shop up.

## 7. Installing or upgrading from a built zip

The dev stack mounts `src/`, so do not unzip a release into its `modules/smailyforprestashop`: that
would write into your `src/`. Use the CI shop instead, which installs `dist/smailyforprestashop.zip`
on start:

    composer --working-dir src package
    KEEP=1 tests/ci/validate.sh                       # shop stays up on http://localhost:8000

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
commands below follow those walks; they were not run again for this document.

1. **Build the 3.0.0 zip first:** `composer --working-dir src package`. The script deletes all of
   `build/` and `dist/` before it builds, so anything kept there goes too.
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
3. **Install the legacy release** in a shop whose module folder is not the mounted `src/` (the CI shop
   with `KEEP=1`, section 7): uninstall the module, unpack the legacy zip into `modules/`, and run
   `prestashop:module install smailyforprestashop`.
4. **Load a fixture** from `tests/fixtures/legacy/` with `mariadb` (fixture README).
5. **Upgrade to 3.0.0** by one of these paths:
   - Module Manager > Upload a module with `dist/smailyforprestashop.zip`. On the flashlight 8.0.0 and
     8.2.8 images the upload fails until the web server can write its upload folder:
     `docker compose exec -u root prestashop chown www-data /tmp/client_body` (use the CI Compose file
     and project for the CI shop).
   - A scripted upload: log in to the admin, then post the zip to
     `/admin-dev/index.php/improve/modules/import` with the session.
   - The console: replace the module folder with the 3.0.0 files (section 7) and run
     `prestashop:module upgrade smailyforprestashop`.
   - An FTP copy: replace the files only. Nothing runs until a cron URL request or a page request
     runs the tick (ARCHITECTURE section 3.7).
6. **Check** `SMAILY_CONNECT_MIGRATION` (`status` `done`), `SMAILY_CONNECT_SCHEMA_VERSION`, the
   account, mapping and cart rows, and that the legacy keys except the two tokens are gone.

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
