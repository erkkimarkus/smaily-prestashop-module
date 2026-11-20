# Local development environment

This recipe runs a local PrestaShop with the module from `src/`. It was run on 2026-11-20 on macOS
(Apple Silicon) with Docker in a Colima VM (2 CPUs, 8 GiB), Docker Compose 5.5.1, from module version
3.0.0 (the skeleton and the event queue). Each step says whether it was verified there.

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
4). Result on PHP 8.5.11: 67 tests, 138 assertions, 24 skipped; PHP CS Fixer: 0 of 88 files to fix.
The run set `PHP_CS_FIXER_IGNORE_ENV=1`, as PHP CS Fixer may not support the newest PHP.

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
`ps_module` row at version 3.0.0, 31 hooks, the 9 `ps_smaily_connect_*` tables and the configuration key
`SMAILY_CONNECT_SCHEMA_VERSION`. A fresh install creates no `smaily_cart` table, no other `SMAILY_*`
key and no override file.

Run the unit and integration tests inside the shop, from the module's own folder:

    docker compose exec -u www-data -e PS_ROOT_DIR=/var/www/html prestashop \
      php modules/smailyforprestashop/vendor/bin/phpunit --configuration /smaily/phpunit.xml.dist

Verified result: 67 tests, 349 assertions, all pass. `PS_ROOT_DIR` makes the test bootstrap boot the
shop, and the integration tests then run against its database. Start PHPUnit from the module folder,
not from `/smaily/src`: PrestaShop includes the module's `vendor/autoload.php` when it builds a
container, and a second Composer autoloader from another path stops PHP with "Cannot redeclare class".

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
clear the cache: `docker compose exec -u www-data prestashop php bin/console cache:clear`.

## 5. PrestaShop 8

Set `PS_IMAGE=prestashop/prestashop-flashlight:8.2.8-8.0-nginx` (PrestaShop 8.2.8 on PHP 8.0) or
`prestashop/prestashop-flashlight:8.0.0-8.0-nginx` (8.0.0 on PHP 8.0) in `.env`, and use a different
`COMPOSE_PROJECT_NAME`, or reset the environment first: the volumes keep the files and the database of
the image they were created with.

Verified on 8.2.8 and 8.0.0 through the CI script (section 6). The dev stack itself was verified on
9.2.0 only.

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
   not to Smaily.
8. The RSS feed answers 200 with an RSS document.
9. The two cron controllers refuse a wrong token.

Result on 2026-11-20: all 10 checks `ok`, with 67 tests and 349 assertions in step 5, on flashlight
8.0.0, 8.2.8 and 9.2.0, and on the official image `prestashop/prestashop:9.2.0-8.1` with
`PS_COMPOSE=docker-compose.official.yaml` (the image CI uses for 9.2.0).

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
`tests/fixtures/legacy/` (see its README).

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
