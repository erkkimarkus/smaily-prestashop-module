# PrestaShop Marketplace requirements

**As read on 2026-11-20 and 2026-11-21; the guidelines change.** This file records what the PrestaShop
Marketplace (addons.prestashop.com) requires of a module, as PrestaShop's own pages said on those two
days, and where this module stands against each technical rule. Each claim names the page it comes
from. Read the pages again before any listing step.

The code follows the Marketplace technical rules from the start. The listing itself
is a separate business step.

## 1. Who may list

- A standard seller pays an annual validation fee of €99 per product
  (https://docs.cloud.prestashop.com/1-getting-started/).
- "Only Official Partners can offer free modules"
  (https://docs.cloud.prestashop.com/1-getting-started/). A seller becomes an Official Partner
  through an application and an evaluation of its portfolio. The terms are not public.
- A search for "smaily" on addons.prestashop.com returned no product on 2026-11-20.

## 2. Submission flow

Sources: https://docs.cloud.prestashop.com/9-submitting-your-module and
https://docs.cloud.prestashop.com/5-submission-and-validation-process.

1. Create a seller account.
2. Create the product page.
3. Put the `module_key` that the Marketplace gives into the module's constructor.
4. Upload a zip whose name is the module's folder name.
5. An automatic validator checks the zip.
6. An expert reviews the module (5 to 10 working days).
7. A marketing review checks the product page (up to 10 working days).

An update of an existing product must be compatible with the latest PrestaShop version.

## 3. Technical rules

Sources, with the letter that section 6 uses for each:

- **T** — https://docs.cloud.prestashop.com/2-technical-development-standards/
- **C** — https://docs.cloud.prestashop.com/10-validation-checklist
- **S** — https://docs.cloud.prestashop.com/9-submitting-your-module
- **M** — https://docs.cloud.prestashop.com/3-content-and-marketing-standards/
- **G** — https://devdocs.prestashop-project.org/9/modules/creation/good-practices/
- **own** — not stated on these pages. The module follows the rule because PrestaShop ships a tool for
  it (`header-stamp`) or because it belongs to reading input through the platform.

Structure of the module and the zip:

1. The technical name is identical across all versions of the module (T).
2. The constructor holds the `module_key` (S).
3. The module holds a `config.xml` (T).
4. Every folder holds an `index.php` that redirects; `vendor` and its subfolders are exempt (T, C).
5. The module root holds an `.htaccess` that refuses `*.php` on Apache 2.2 and 2.4 (T, C).
6. Every PHP file starts with `if (!defined('_PS_VERSION_')) { exit; }` (T, C).
7. The module declares `ps_versions_compliancy`, with `9.99.99` as the upper end (T).
8. The module runs on the latest PrestaShop version (T).
9. The zip is named after the module and holds one folder of that name with one module; `$this->name`
   is a lowercase literal (S, C).
10. Every source file carries the licence notice (own).

Database, core and other modules:

11. No `ALTER` on a core table; the module's own tables are created with `IF NOT EXISTS` and dropped
    with `IF EXISTS` (T, C, G).
12. No change to the files of the core or of another module (T, C).
13. An override only where it cannot be avoided (T, G).
14. Uninstall removes the module's configuration, tables and admin tabs (G).

Code quality:

15. Zero errors, warnings and notices in debug mode (T, C).
16. Code, comments and default texts are in English; other languages go through PrestaShop's
    translation system (T, C, G).
17. No obfuscated code (C).
18. No debug statements, no commented-out code, no empty or generated files (C).
19. The PrestaShop coding standards (G).
20. HTML comes from Smarty or Twig templates, not from PHP (C).
21. Classes outside a namespace and configuration keys carry the module's prefix (T, C).
22. The module writes files only where PrestaShop keeps writable files (C, G).
23. No iframe (C).

Security:

24. Every variable in an SQL statement is made safe at the statement: `pSQL()` for a string, an
    `(int)` cast for an integer, `array_map()` for a list, `bqSQL()` for a table or column name (T, C).
25. Template output is escaped (T, C).
26. Request input is read through the platform, not from a superglobal (own).
27. No `serialize()`, `unserialize()` or `eval()` (T, C).
28. No file operation on a path that comes from the request (T).
29. Cron and AJAX work runs in a front controller behind a secret token that is compared with
    `hash_equals()` (T, C).
30. External domains that the module depends on are listed in a `header_csp.txt` file (C).

A self-contained archive:

31. No external asset is downloaded after the install (C).
32. No external links in the code or the documentation (C, G).
33. A file that an update no longer ships is deleted by the update script with `unlink()` (C).
34. Hook code stays light, and assets load only on the pages that need them (T, C).

Licence and documentation:

35. The module and its dependencies use a supported licence. The page lists Apache, AFL, MIT, BSD, ISC
    and EUPL (C).
36. The documentation is a PDF in `docs/`, named `readme_[isocode].pdf`; `readme_en.pdf` is
    mandatory (C, M).

## 4. Product page

Source: https://docs.cloud.prestashop.com/3-content-and-marketing-standards/.

- An icon.
- A short description of at most 250 characters.
- Features and benefits.
- At least 5 keywords.
- At least 3 screenshots per language, 1000 × 1000 pixels or larger.
- A demo: "highly recommended, soon mandatory".

## 5. How a merchant gets an update

Source: https://help-center.prestashop.com/hc/en-us/articles/24491043284882-FAQ-Marketplace-Support-and-updates.

The merchant logs in to their Marketplace account in the back office, sees a notification in the
Modules menu and clicks "Update". The update takes one click and is not automatic. A module that was
installed from a GitHub zip gets no such notification. This is why the module has its own new-version
check (`docs/ARCHITECTURE.md` sections 10.7 and 11.5).

## 6. Where this module stands

Read from branch `v3` on 2026-11-21. "The zip" is `dist/smailyforprestashop.zip`, which
`composer --working-dir src package` builds: the files that git tracks under `src/`, minus
`src/.zipignore`, plus the production `vendor/` folder. Section 6.4 gives the commands behind the
counts. PrestaShop's online validator was not run: it takes an upload of the zip, and no code of this
module has been uploaded to an outside service.

The zip holds 330 files: 319 of the module (251 PHP, 37 Twig, 13 YAML, 7 JavaScript, 3 SVG, and one
each of CSS, XML, XLIFF, PNG, PDF, `composer.json`, `composer.lock` and `.htaccess`) and 11 of
Composer's autoloader in `vendor/`. It holds no test, no development configuration, no Markdown file
and no operating-system file.

The counts of this section were taken before the Automations tab was added. That change brought 2
PHP files and 3 Twig templates, so git now tracks 324 module files for the zip (253 PHP, 40 Twig);
each of the five new files carries the licence notice, and both PHP files the `_PS_VERSION_` check.
The other counts were not taken again. Since then the 8 classes of the 2.x contact sync and cart
reminder left the tree (rule 33 below), which takes 8 PHP files out of every count above.

### 6.1 Technical rules that the module meets

| # | Rule | Evidence |
|---|---|---|
| 1 | Same technical name | `$this->name` is `smailyforprestashop` in the main class of 1.6.2, 2.2.0 and 3.0.0: 1 distinct value. |
| 2 | `module_key` | The constructor sets it; 1.6.2, 2.2.0 and 3.0.0 hold 1 distinct value. |
| 5 | Root `.htaccess` | `src/.htaccess` refuses `*.php` in an Apache 2.2 block and an Apache 2.4 block. Folder listings are closed by the `index.php` files (rule 4). |
| 6 | `_PS_VERSION_` check | 192 of the 192 PHP files that are not an `index.php` hold the check in the exact form. The 59 `index.php` files are PrestaShop's own redirect stub, which has no such check. |
| 7 | `ps_versions_compliancy` | `min` `8.0.0`, `max` `9.99.99`. |
| 8 | Runs on a current PrestaShop | The test suite passes inside PrestaShop 9.2.0 and 8.2.8 (section 6.4). |
| 9 | Zip name and folder | `smailyforprestashop.zip` holds 1 top-level entry, the folder `smailyforprestashop`; `$this->name` is a lowercase literal. 0 `__MACOSX` or `.DS_Store` entries. |
| 11 | No `ALTER`; guarded `CREATE` and `DROP` | 0 `ALTER`. 2 of 2 `CREATE TABLE` statements carry `IF NOT EXISTS`, 2 of 2 `DROP TABLE` statements carry `IF EXISTS`. |
| 12 | No change to core or other modules' files | The module writes no file (0 calls of `file_put_contents`, `fopen`, `fwrite`, `copy`, `rename`, `mkdir`). It deletes one file: the override of `ps_emailsubscription` that 2.x installed, and only when its SHA-256 is that of the 2.x copy. The contact reconcile writes data, not files: `customer.newsletter` and `emailsubscription.active`. |
| 13 | Overrides | The module ships no `override` folder. |
| 14 | Uninstall | A console uninstall on 8.2.8 and on 9.2.0 left 0 `SMAILY%` configuration rows (5 before), 0 tables (9), 0 admin tabs (5), 0 hook registrations (32) and 0 tab roles (20). |
| 15 | Debug mode | Both dev shops run with `_PS_MODE_DEV_` on. The suite passes in them with PHPUnit turning warnings and notices into failures. 15 storefront and module requests and the module's 5 admin menu links per shop returned 0 answers with PHP error text; the shop's output held 0 PHP messages that name the module. The admin links were opened before the Initial setup, so each showed the Initial setup page. While the suite ran on 9.2.0, PrestaShop's own log took 5 lines "Cannot register tab … because it already exists"; a plain install and the requests above wrote none. |
| 16 | English; translations through PrestaShop | 0 source files hold a non-ASCII letter. Texts go through the translator: 190 `trans()` calls in PHP, 333 `trans` filters in Twig, domains `Modules.Smailyforprestashop.Admin` and `.Shop`; 0 literal user texts in JavaScript. Estonian is one XLIFF file in `translations/et-EE/`. |
| 17 | No obfuscation | 0 calls of `base64_decode`, `gzinflate`, `gzuncompress`, `str_rot13`, `create_function`. No minified script or style: the longest line of the JavaScript and CSS is 181 characters. |
| 18 | No debug statements, dead code, empty files | 0 calls of `var_dump`, `print_r`, `var_export`, `dump`, `dd`, `error_log`; 0 `console.*` in JavaScript; 0 commented-out statements found by pattern; 0 empty files; no log or generated file. |
| 19 | Coding standards | PHP CS Fixer with PrestaShop's configuration: 0 of 251 files to fix. PHP 8.0 lint: 0 errors. |
| 21 | Prefixes | The 6 classes outside a namespace are the main class and the 5 front controllers, named as PrestaShop requires. The 48 keys that the uninstall removes start with `SMAILY_`: `Config\Settings` puts `SMAILY_CONNECT_` in front of every 3.0.0 key. The module's tables start with `smaily_`. The prefix is `SMAILY_`, not the technical name, because 2.x stores hold keys under it. |
| 22 | File storage | The module writes no file (rule 12). |
| 23 | No iframe | 0 `<iframe`. |
| 25 | Escaped output | All 37 templates are Twig, with PrestaShop's automatic escaping: 0 `raw` filters, 0 `autoescape` switches, no Smarty file. The RSS feed is built with `DOMDocument` nodes. One script sets `innerHTML`, with HTML that a Twig template rendered. |
| 28 | No file operation on request input | The 5 file calls (`is_file`, `hash_file`, `unlink`, `scandir`, `rmdir`) take the fixed path of the 2.x override; 1 `require_once`, of the module's own autoloader. |
| 31 | Nothing downloaded after install | No code downloads or writes a file. 0 scripts, styles, fonts or images are loaded from another host. The daily new-version check reads release data from `api.github.com` and never fetches the release. |
| 35 | Licence of the dependencies | The production `vendor/` holds Composer's autoloader only (MIT). The module's own licence is in section 6.3. |

### 6.2 Technical rules that the module meets since 2026-11-21

| # | Rule | What changed, and where | Evidence now |
|---|---|---|---|
| 3 | `config.xml` | `src/config.xml` is the file that PrestaShop 8.2.8 and 9.2.0 write for the English back office (both write the same bytes). Git ignores only the copies for other languages. `tests/Unit/Install/ConfigXmlTest.php` compares the file with the main class. | The zip holds `config.xml`. |
| 4 | `index.php` in every folder | `src/docs/index.php` was added. The Composer script `autoindex` now leaves `vendor` out. | 59 of 59 folders outside `vendor` hold an `index.php`; the 59 files are identical. |
| 10 | Licence notice in every source file | PrestaShop's `header-stamp` wrote the module's existing notice, as a Twig comment, at the top of the 37 templates. The Composer script `header-stamp` runs it with the text in `bin/license-header.txt`. | Notice in 251 of 251 PHP, 37 of 37 Twig, 7 of 7 JavaScript and 1 of 1 CSS files. The Twig token stream of each of the 37 templates is the same as before the notice. |
| 26 | No direct superglobal | `src/controllers/front/capture.php` reads the method and the posted fields from Symfony's request object instead of `$_SERVER` and `$_POST`. | 0 superglobals in the module. The other request reads are 5 `Tools::getValue()` calls (each cast, checked against a list, or compared with `hash_equals()`) and Symfony's request object in the admin controllers. |

### 6.3 Not technical, or waiting for the owner

| # | Rule | Standing | Question |
|---|---|---|---|
| 20 | HTML from templates | 1 place builds markup in PHP: `Admin\BackOfficeNotice` returns an inline script for every back-office page (its values are JSON-encoded with the HEX flags). The 2 cron controllers and the capture URL print plain text or JSON, not HTML. | May the notice's script move to a template, which changes the code path of every back-office page? |
| 24 | SQL values made safe at the statement | 139 statements hold SQL text. Strings go through `pSQL()`: 36 variables directly and 48 through a helper (`quote()`, `like()`). Integers are typed `int` and written with `%d` (25 places) or joined as they are; 7 variables carry an `(int)` cast at the statement. The statements that take request values (the Log filters, the capture URLs, the queue and job helpers) were read: each value is cast, escaped, matched by a pattern or checked against a list. The other statements were not read one by one. | Should every integer get a literal `(int)` at its statement (about 60 places, the queue and the upgrade among them), or does the typed form stand until the validator has been run? |
| 27 | No `unserialize()` | 0 `serialize()`, 0 `eval()`, 1 `unserialize()`: `Migration\LegacyConfigMapper` reads the field lists that 1.6.x stored serialized, with `allowed_classes => false`. The upgrade needs this read (`docs/ARCHITECTURE.md` section 3.2). | Does the read stay as it is, or does a hand-written parser of the two list formats replace the call? |
| 29 | Front controllers behind a token | The 2 cron URLs and the tick URL compare a stored token with `hash_equals()` and refuse an empty one; the capture URL compares the token of the visitor's own cart (an HMAC of the cart's id) the same way. The RSS feed URL takes no token: merchants embedded it in their Smaily templates, and Smaily fetches it. | Is the public, read-only feed acceptable to the Marketplace, given that a token would break the feeds that 2.x stores use? |
| 30 | `header_csp.txt` | The pages of the module load nothing from another host. The server calls `<subdomain>.sendsmaily.net` and `api.github.com`. The page does not say whether server-side calls belong in the file. | Does the reviewer expect a `header_csp.txt` with these two hosts? |
| 32 | No external links | 296 files carry `http://www.gnu.org/licenses/` in the licence notice. 10 other files name an outside address: the Smaily API and the GitHub API, XML namespaces in the RSS feed and the 3 SVG logos, the example subdomain in 3 files, and the "Create a Smaily account" link. `composer.lock` holds 487 package addresses. The links of `USERGUIDE.pdf` are not counted. | Which of these does the Marketplace accept, and does the licence notice lose its link? |
| 33 | Deleting files that an update drops | 2.2.0 shipped 35 files that 3.0.0 does not ship (the 2.x settings forms, `lib/`, the old templates and script, and the 8 classes of the 2.x contact sync and cart reminder, which no URL of 3.0.0 reaches). The upgrade deletes none of them; it deletes only the 2.x override in the core's folder. | May the 3.0.0 upgrade get a step that deletes these 35 files from the module folder? |
| 34 | Light hooks | Not measured in this pass. `docs/ARCHITECTURE.md` section 3.7 holds the measured page cost of the fallback tick. | Is a measurement of each hook's page cost wanted before a listing? |
| 35 | Supported licence | GPL-3.0: `LICENCE.txt` at the repository root, `"license"` in `src/composer.json`, and every notice. GPL-3.0 is not among the listed licences. The zip holds no licence file. | Does the Marketplace accept GPL-3.0, or does the listing need another licence? |
| 36 | `docs/readme_en.pdf` | The zip holds `docs/USERGUIDE.pdf`, the 2.x guide; no `readme_en.pdf`. | Does the 3.0.0 guide ship as `docs/readme_en.pdf`, with the old file deleted by the upgrade (rule 33)? |
| — | The automatic validator | Not run (see above). | May the zip be uploaded to PrestaShop's validator? |
| — | Seller account, Official Partner status, fee, product page | Sections 1, 2, 4 and 7. | Who lists the module, and as a free or a paid product? |

### 6.4 How the counts were taken

The zip and its content:

    composer --working-dir src package
    unzip -qq dist/smailyforprestashop.zip -d /path/outside/the/repository
    find smailyforprestashop -type f | wc -l
    find smailyforprestashop -type d -not -path '*/vendor*' | while read d; do [ -f "$d/index.php" ] || echo "$d"; done
    grep -L 'NOTICE OF LICENSE' $(find smailyforprestashop -type f \( -name '*.php' -o -name '*.twig' -o -name '*.js' -o -name '*.css' \) -not -path '*/vendor/*')

The licence notice, the index files, the code style and the tests, from the repository root:

    composer --working-dir src header-stamp -- --dry-run     # exits 0 when no PHP or Twig file lacks the notice
    composer --working-dir src autoindex                     # adds a missing index.php; stamp it afterwards
    (cd src && vendor/bin/php-cs-fixer fix --dry-run --using-cache=no)
    src/vendor/bin/phpunit

Forbidden constructs are counted with `grep` over the PHP files that the zip holds, for example
`grep -nE '\$_(GET|POST|REQUEST|SERVER|COOKIE|FILES|SESSION|ENV)\b'` and
`grep -nE '(^|[^a-zA-Z_>:])(serialize|unserialize|eval)\('`.

The tests in a shop, the debug-mode requests and the uninstall follow `docs/DEV_ENVIRONMENT.md`
(sections 4, 4a and 6). Last results, on PrestaShop 8.2.8 (PHP 8.0) and 9.2.0: the suite of 1266 tests
passes in both shops, and `tests/ci/validate.sh` passes its 10 checks with the zip.

## 7. What a listing needs outside the code

- A PDF user guide in English (`docs/readme_en.pdf` in the module).
- The product page texts: short description, features, benefits, keywords.
- Screenshots: at least 3 per language, 1000 × 1000 pixels or larger.
- An icon of 256 × 256 pixels.
- A demo.
