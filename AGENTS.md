# HestaHub for WordPress

A WordPress plugin that shows a HestaHub Tenant's Apartments, availability and Clinics on the Tenant's own website. It reads them from the HestaHub Public Tenant API, server-side, and caches them. It writes nothing back to HestaHub.

## The contract comes first

**[docs.hestahub.com/v1/](https://docs.hestahub.com/v1/)** is the source of truth for everything on the wire and for most of what this plugin must do: routes, caching and serving stale, what a route shows when there is no data, escalation, templates and hooks. Its JSON Schemas are under `https://docs.hestahub.com/v1/<revision>/`. Read the contract before changing anything that talks to the API or renders its data. If this repo and the contract disagree, the contract wins, and the disagreement is a bug to report.

The plugin is a **tolerant reader**: it reads field by field, ignores unknown keys, and falls back on unknown enum values. It never validates a whole response against a schema at runtime. The schemas are for tests.

## Fixed decisions

- **Names:** slug, folder and text domain `hestahub`; PHP namespace `HestaHub\`.
- **License:** GPLv2-or-later, like WordPress. Everything vendored must be compatible.
- **Floors:** `Requires PHP: 7.4`, `Requires at least: 6.8`. Write PHP 7.4 syntax: no `match`, enums, `readonly`, constructor promotion, named arguments or `?->`. CI runs the floor and current stable PHP, plus PHPCompatibility at `testVersion 7.4-`. Below PHP 8.0 the plugin shows an admin notice saying "PHP 7". It never blocks.
- **Requests:** always `https://` literally; `Authorization: Bearer <key>`; `User-Agent: HestaHub-WP/<plugin> (WordPress <wp>; PHP <php>)` on every request.
- **The API key:** read from an environment variable, then a `wp-config.php` constant (`HESTAHUB_API_KEY`), then the database. The settings screen shows which source is in use and masks the key (last four characters).
- **Updates:** `Update URI` set from the first release; a vendored `plugin-update-checker` reads a static JSON manifest and a release zip on GitHub Releases. The whole update mechanism sits behind **one conditional**, so a wordpress.org build can strip it. Auto-updates stay off unless the webmaster turns them on.
- **Releases:** CI builds the zip on a tag. Built files are never committed.
- **Schemas:** vendored into this repo for the test suite. CI fails if the vendored copy differs from the published one for the Revision the plugin claims. The plugin declares the minimum Revision it was built against.
- **Rendering:** structural CSS with no colour opinions, a few CSS custom properties, and PHP templates a theme overrides by copying them into `<theme>/hestahub/` (core's `locate_template()`). A shortcode and a template tag in v1; a block only once a Tenant runs a block theme.
- `readme.txt` in WordPress readme format from the first release, disclosing the external service and linking the contract.

## Commands

Needs PHP, Composer, gettext and a MySQL or MariaDB server.

- **Install:** `composer install`, then `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]` once. It puts WordPress and its test suite in `.wp-tests/` and creates the database. `wp-version` is `latest` or a release such as `6.8`.
- **Test:** `composer test` (PHPUnit with the WordPress test suite); `WP_MULTISITE=1 composer test` for multisite. The suite caps PHPUnit at 9, and `composer.lock` is resolved for PHP 7.4: update dependencies with `composer update`, never by hand.
- **Lint and compatibility:** `composer lint` (syntax) and `composer compat` (PHPCompatibility, `testVersion 7.4-`).
- **Build:** `bin/build-zip.sh [tag]` writes `build/hestahub-<version>.zip`. The version must agree in `hestahub.php`, `Plugin::VERSION` and the `readme.txt` Stable tag, and match the tag if one is given. With a tag it also writes `build/hestahub.json`, the update manifest. Pushing a `v*` tag runs CI, then the same script, and attaches the zip and the manifest to a GitHub Release.
- **Contract schemas:** `bin/vendor-schemas.sh [revision]` vendors the schemas and examples into `tests/fixtures/api/` (default: `Client::REVISION`). `bin/vendor-schemas.sh --check` fails if they differ from the published ones; CI runs it. Raising `Client::REVISION` means re-vendoring in the same change.
- **Translations:** every string is in the `hestahub` text domain. After changing strings, `bin/i18n.sh` regenerates `languages/hestahub.pot` and merges it into each `languages/hestahub-<locale>.po`; translate what is new or fuzzy. `bin/i18n.sh --check` fails on a stale `.pot` or an incomplete `.po`; CI runs it. The build compiles the `.mo` files; they are never committed.
- **wordpress.org build:** `bin/build-zip.sh --wporg` leaves out the update channel (`updates.php`, `lib/plugin-update-checker/`, the `Update URI` header).
- **Update the vendored update checker:** `bin/vendor-update-checker.sh <tag>` replaces `lib/plugin-update-checker/` with that release. The pinned tag is in its `VERSION` file.

## Code conventions

- Features implement `HestaHub\Feature`, are added in `hestahub.php`, and hook into WordPress in `register()`, which runs once on `plugins_loaded`.
- The update channel lives in `updates.php` and nowhere else. Outside it, only two things may refer to updates: the `file_exists()` require in `hestahub.php` (the one switch) and the `Update URI` header, which can't live anywhere else and which the `--wporg` build strips. Nothing may refer to `lib/plugin-update-checker/` except `updates.php`.
- Only `HestaHub\Api\Client` talks to HestaHub. Everything else reads through `Client::get()`, which serves the cached copy, refetches past `ttl`, serves stale on any failure until `stale_ttl`, and backs off. TTLs come only from the body: never add a number of your own or a setting for one.
- Every option, transient and site transient the plugin stores is named `hestahub_…`. `uninstall.php` deletes by that prefix.

## Public repository

This repo, its issues and pull requests are public. Don't name a Tenant's people. Don't describe a Tenant's hosting or website beyond what is needed to state a requirement. Never commit an API key, not even a revoked one.

## Agent skills

### Issue tracker

Issues live as GitHub issues in `blackbucksoftware/hestahub-wordpress`, managed via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its role name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
