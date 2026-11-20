# Local development environment

This recipe runs a local PrestaShop with the module from `src/`. It was run on 2026-11-20 on macOS
(Apple Silicon) with Docker in a Colima VM (2 CPUs, 8 GiB, 30 GiB Docker disk), Docker Compose 5.5.1,
from module version 2.2.0. Each step says whether it was verified there.

The admin user and password are the demo ones in [CONTRIBUTING.md](../CONTRIBUTING.md).

## 1. Host tools

The host needs Docker with Compose, PHP 8.1 or newer and Composer. Verified with PHP 8.5.11 and Composer
2.10.3 from Homebrew. Without PHP on the host, run Composer in a container (not verified):

    docker run --rm -v "$PWD":/app -w /app composer:2 install --working-dir src/

Install the dependencies. The dev stack mounts `src/` with its `vendor/` into the shop, so this step
comes first:

    composer install --working-dir src/

## 2. Unit tests and code style (verified)

    src/vendor/bin/phpunit
    (cd src && vendor/bin/php-cs-fixer fix --dry-run --using-cache=no)

Result on PHP 8.5.11: 8 tests, 23 assertions, all pass; PHP CS Fixer: 0 of 51 files to fix.
CI runs the same on PHP 8.1. The verified run set `PHP_CS_FIXER_IGNORE_ENV=1`, as PHP CS Fixer may
not support the newest PHP.

## 3. Pick a project name and free ports

`docker-compose.yaml` reads these variables, defaults in brackets:

| Variable | Default | Use |
|---|---|---|
| `PS_IMAGE` | `prestashop/prestashop-flashlight:9.2.0-nginx` | Shop image, e.g. `prestashop/prestashop-flashlight:8.2.8-8.0-nginx` |
| `PS_PORT` | `8080` | Host port of the shop; also sets `PS_DOMAIN` |
| `PMA_PORT` | `8888` | Host port of phpMyAdmin |
| `MAILHOG_PORT` | `8025` | Host port of the MailHog UI |

Put them in a `.env` file at the repository root. Git ignores `.env`, and Compose reads it on its own.
Check that the ports are free first:

    for p in 8081 8082 8026; do lsof -nP -iTCP:$p -sTCP:LISTEN >/dev/null && echo "$p busy"; done

The verified run used this `.env`, because other projects held 8080 and 8888:

    COMPOSE_PROJECT_NAME=smaily-presta-dev
    PS_PORT=8081
    PMA_PORT=8082
    MAILHOG_PORT=8026

## 4. Start the shop (verified on PrestaShop 9.2.0)

Start only the shop and its database. phpMyAdmin and MailHog are optional and cost memory:

    docker compose up -d prestashop          # add phpmyadmin mailhog when needed

The first start pulls the image (1.5 GB for 9.2.0) and restores the demo database; the storefront
answered HTTP 200 about 2.5 minutes after the command, most of it the pull.

The shop does **not** install the module on its own. `src/` is only mounted into
`modules/smailyforprestashop`. Install it with the PrestaShop console:

    docker compose exec -u www-data prestashop php bin/console prestashop:module install smailyforprestashop

Verified result: `Install action on module smailyforprestashop succeeded`, `ps_module` row version
2.2.0, active, three hooks, the `ps_smaily_cart` table and the `SMAILY_*` defaults.

- Storefront: `http://localhost:$PS_PORT/`
- Admin: `http://localhost:$PS_PORT/admin-dev/` (PrestaShop 9 serves the login at `/admin-dev/login`)
- Module configuration: `http://localhost:$PS_PORT/admin-dev/index.php?controller=SmailyForPrestaShopConfiguration`,
  which redirects to `/admin-dev/index.php/modules/smailyforprestashop/configuration`. Verified: HTTP 200
  after login, page title "Smaily for Prestashop", the account form present.
- Database: `docker compose exec mysql mariadb -uprestashop -pprestashop prestashop`

Memory of the running pair on PrestaShop 9.2.0: shop 344 MiB, MariaDB 156 MiB, about 0.5 GiB together.
Disk: the 9.2.0 image 1.54 GB, the 8.2.8 image 1.12 GB, the two volumes about 0.7 GB.

Code changes in `src/` show at once, as the folder is mounted. After a change to services or routes,
clear the cache: `docker compose exec -u www-data prestashop php bin/console cache:clear`.

## 5. PrestaShop 8

Set `PS_IMAGE=prestashop/prestashop-flashlight:8.2.8-8.0-nginx` (the CI image) in `.env` and use a
different `COMPOSE_PROJECT_NAME`, or reset the environment first: the volumes keep the files and the
database of the image they were created with.

Verified on 8.2.8 through the CI script only (step 6): the zip installs, hooks, table, override, RSS feed
and cron controllers check out. The dev stack on 8.2.8 with the admin configuration page was **not**
verified: its database container stopped with "No space left on device", because the Colima VM's
Docker disk was full (see "Disk").

## 6. CI validation script (verified)

    composer --working-dir src package
    PS_IMAGE=prestashop/prestashop-flashlight:8.2.8-8.0-nginx tests/ci/validate.sh
    PS_IMAGE=prestashop/prestashop-flashlight:9.2.0-nginx tests/ci/validate.sh

`composer package` built `dist/smailyforprestashop.zip` (424 KB, 98 files, no dev dependencies).
`validate.sh` passed all 10 checks on each image, in about 70 seconds once the image was present.
It uses its own Compose project (`ci`) on port 8000 and removes its containers and volumes at the end.
The API client check sends its request to a mock transport, not to Smaily.

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

## 9. Several git worktrees at once

Recommended: each worktree gets its own `.env` with its own `COMPOSE_PROJECT_NAME` and ports. Compose
then keeps separate containers, volumes and networks per worktree, and each shop mounts the `src/` of
its own worktree:

    COMPOSE_PROJECT_NAME=smaily-presta-<worktree>
    PS_PORT=8181
    PMA_PORT=8182
    MAILHOG_PORT=8126

Verified: the project name and the port variables (one worktree, project `smaily-presta-dev`, ports
8081/8082/8026). **Blocker:** `docker-compose.yaml` still sets fixed `container_name` values
(`prestashop`, `prestashop_db`, `phpmyadmin`, `mailhog`), and Docker allows each name once per machine.
Until those lines go, only one dev stack can run at a time, whatever the project name. The same holds
for `tests/ci/validate.sh`, which always uses project `ci` and port 8000.

Run one shop at a time anyway when memory is short: one shop with its database takes about 0.5 GiB.

## Disk

The verified run filled the Colima VM's 30 GiB Docker disk: other projects' images and volumes took
most of it, and the two flashlight images (2.7 GB) and the volumes of this stack (about 1 GB) took the
rest. A full Docker disk breaks every container that writes, also other projects' databases. Check
before a pull:

    colima ssh -- df -h /var/lib/docker
    docker system df
