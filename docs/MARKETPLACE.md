# PrestaShop Marketplace requirements

**As read on 2026-11-20; the guidelines change.** This file records what the PrestaShop Marketplace
(addons.prestashop.com) requires of a module, as PrestaShop's own pages said on 2026-11-20, and where
this module stands against each technical rule. Each claim names the page it comes from. Read the
pages again before any listing step.

The code follows the Marketplace technical rules from the start (Linear CON-379). The listing itself
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

## 3. Mandatory technical rules

Sources: https://docs.cloud.prestashop.com/2-technical-development-standards/ and
https://docs.cloud.prestashop.com/10-validation-checklist.

1. The technical name is identical across all versions of the module.
2. The constructor holds the `module_key`.
3. The zip holds a `config.xml`.
4. Every folder holds an `index.php` (the `vendor` folder excluded).
5. The module root holds an `.htaccess`.
6. Every PHP file starts with `if (!defined('_PS_VERSION_')) { exit; }`.
7. The module declares `ps_versions_compliancy`.
8. No `ALTER` on a core table.
9. No change to other modules or to the core.
10. No external asset is downloaded after the install.
11. Zero errors in debug mode.
12. Input is sanitised and template output is escaped.
13. No `serialize` or `eval`.
14. No obfuscated code.
15. No external links in the code or the documentation.
16. A supported licence. The pages list Apache, AFL, MIT, BSD, ISC and EUPL.
17. The documentation is a PDF in `docs/`: `readme_en.pdf`; English is mandatory.
18. An override only where it cannot be avoided.

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

Read from branch `v3` on 2026-11-20. "The packaged module" is what `bin/package.sh` puts in the zip:
the files that git tracks under `src/`, minus `src/.zipignore`, plus the production `vendor/`
folder. The counts below leave `vendor/` out. Facts only: this table proposes no fix.

| # | Rule | Standing | Evidence |
|---|---|---|---|
| 1 | Same technical name in every version | Met | `smailyforprestashop` in the 1.6.2, 2.2.0 and 3.0.0 main class (CLAUDE.md rule 1). |
| 2 | `module_key` | Met | `src/smailyforprestashop.php` sets `module_key`; 1.6.2 and 2.2.0 carry the same value. |
| 3 | `config.xml` | Not met | No `config.xml` in the packaged module: `src/.gitignore` ignores it (PrestaShop writes it when it loads the module). |
| 4 | `index.php` in every folder | Not met | 1 of 35 folders has none: `docs/` (it holds `USERGUIDE.pdf`). |
| 5 | Root `.htaccess` | Met | `src/.htaccess` exists; it refuses direct requests to `*.php` on Apache 2.2 and 2.4. |
| 6 | `_PS_VERSION_` guard in every PHP file | To check | 114 of 148 PHP files carry the guard. The 34 without it are all `index.php` files, the standard redirect stub (`header('Location: ../'); exit;`). Whether the validator exempts them is not known. |
| 7 | `ps_versions_compliancy` | Met | `min` `8.0.0`, `max` `9.99.99`. |
| 8 | No `ALTER` on a core table | Met | 0 `ALTER` statements in the module code. The module creates and drops only its own tables. |
| 9 | No change to other modules or the core | To check | The module ships no override. The upgrade and the uninstall delete the 2.x override file of `ps_emailsubscription` only when its hash is the 2.x copy. The reconcile writes `customer.newsletter` and `emailsubscription.active` (data, not code). |
| 10 | No external asset downloaded after install | To check | No code downloads a file. The daily new-version check reads release data from `api.github.com` and never downloads the release. The logos of Story 3.1 are not on `v3` yet. |
| 11 | Zero errors in debug mode | To check | The in-shop suite runs in the flashlight shop with its default settings; no check reads the debug-mode error log. |
| 12 | Sanitised input, escaped output | To check | Not reviewed. The 2.x admin page and the RSS feed are still wired; the 3.0.0 admin is being built (Story 3.1). |
| 13 | No `serialize` / `eval` | To check | 0 `serialize()` and 0 `eval()` calls. 1 `unserialize()` call: `src/src/Migration/LegacyConfigMapper.php` reads the 1.6.x serialized field lists, with `allowed_classes => false`. |
| 14 | No obfuscated code | Met | All code is plain PHP, Twig and JavaScript source. |
| 15 | No external links in code or documentation | Not met | 149 files carry `http://www.gnu.org/licenses/` in their licence header. 8 packaged files carry other outside addresses: the Smaily API (`src/src/Smaily/Client.php`), the GitHub API (`src/src/Update/LatestRelease.php`), XML namespaces and Smaily's RSS schema (`src/src/Controller/RssFeedController.php`), an example subdomain (`src/src/Form/AccountConfigurationFormType.php`, the Estonian XLIFF file), two Smaily help links in the 2.x admin templates (`accountConfiguration.html.twig`, `customerSyncConfiguration.html.twig`), and package and funding links in `composer.lock`. The links of `USERGUIDE.pdf` are not counted. |
| 16 | Supported licence | Not met | GPL-3.0: `LICENCE.txt` at the repository root, `"license"` in `src/composer.json`, and every file header. GPL-3.0 is not among the listed licences. The packaged module holds no licence file. |
| 17 | `docs/readme_en.pdf` | Not met | `src/docs/USERGUIDE.pdf` exists; no `readme_en.pdf`. |
| 18 | Overrides only where unavoidable | Met | 3.0.0 ships no override (`docs/ARCHITECTURE.md` section 2.4). |

**Possible blockers that the owner flagged.** Each is answered from the guidelines when Linear CON-379
is worked:

1. The module's GPL-3.0 licence against the listed licences (row 16).
2. The "Create a Smaily account" link against the external-links rule (row 15). The link belongs to
   the siblings' invitation banner, which the admin shell (Story 3.1) builds; it is not on `v3` yet.
3. Reading the legacy 1.6.x serialized settings against the serialize rule (row 13). The upgrade needs
   this read to carry 1.6.x field lists over (`docs/ARCHITECTURE.md` section 3.2).

## 7. What a listing needs outside the code

- A PDF user guide in English (`docs/readme_en.pdf` in the module).
- The product page texts: short description, features, benefits, keywords.
- Screenshots: at least 3 per language, 1000 × 1000 pixels or larger.
- An icon.
- A demo.
