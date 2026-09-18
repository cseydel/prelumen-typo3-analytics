# Prelumen Analytics for TYPO3

Composer extension `prelumen/typo3-analytics` (`prelumen_analytics`), TYPO3 12.4/13.4, PHP 8.2+ and Sodium. No metadata or commerce uploads. Footer badge defaults off.

## Installation and upgrade

Require the extension with Composer; the release depends on stable `prelumen/analytics-core:^1.0`. Until Core 1.0 is published, development/test root projects must declare a path repository with version `1.0.0` explicitly. The extension's own development repository mapping is not inherited by consuming projects. Do not substitute `dev-main` in releases.

Apply the TYPO3 database schema update (creates `tx_onecoanalyticspro_state`), flush system **and frontend page caches** once on upgrade to remove the previous cached listener output, and purge any reverse-proxy/CDN HTML cache. Existing manual `onecoAnalyticsPro` settings remain readable; new configurations use `prelumenAnalytics`. The public configuration example is in `Configuration/SiteConfiguration/config.example.yaml`.

This implementation is Composer-only. A standalone non-Composer TER installation and bundled dependency delivery are not claimed.

## Connect each site

Open **Site Management → Prelumen Analytics**. Select an existing account or register, then select/create the organisation and matching project at Prelumen and explicitly approve access as owner. Return to TYPO3 and complete the connection. Pending requests expire; disconnect an expired request before restarting. Completion can be retried after network failures.

The site base must be its canonical, public, absolute HTTPS URL. Relative bases need an absolute HTTPS base for onboarding. Site subpaths and language bases are supported by TYPO3's site resolver. Distinct sites must have distinct canonical bases; language hostnames must also be authorised by the backend website. Never copy an installation state record to another site.

Connection state, recovery material and reports are authenticated-encrypted in the database, bound to the TYPO3 site identifier and `SYS.encryptionKey`. They never enter exported Site YAML. Back up the key and database together. Renaming site identifiers or rotating the key requires restoring/re-authorising credentials; it must not silently fall back to manual tracking.

Backend users need module access, membership in the site's root-page webmount and edit permission on the root page (administrators are allowed). Mutating actions require POST and a per-site TYPO3 form-protection token. Each visible dashboard checks current entitlements independently of its report cache; unconfirmed access hides reports. Free retains historical scores but excludes daily checks and detailed findings. Upgrades and external changes are confirmed only by the backend, never by a browser return parameter.

Disconnect stops local injection immediately. Failed remote revocation retains credentials for retry. Recovery retains website, history and plan; no new trial. Switching back to a saved manual connection is explicit. Domain/base changes stop the managed connection and require explicit reconnection.

## Refresh and tracking

Schedule `vendor/bin/typo3 prelumen:refresh` **every five minutes** with your existing scheduler/cron. It checks current status for each site and throttles report retrieval to five minutes. Without a confirmed status newer than ten minutes, managed frontend injection fails closed. Dashboard visits also check status and refresh reports older than a day. Remote revocation detection is bounded by this polling interval; local disconnect/suspension applies on the next response, including TYPO3 page-cache hits.

The response middleware injects outside TYPO3's page cache and respects frontend group exclusions on every request. Injected responses use `Cache-Control: private, no-store` so external HTML caches cannot preserve an old access decision. Keep the challenge route `/?prelumen=challenge` uncached, including under a site subpath.

Use `consentManaged: true` and call `window.prelumenConsent('none'|'basic'|'full')`, or configure `consentCookie` with those literal values. Cookie changes are checked every second, on focus and on `prelumen:consent-change`. Unknown/malformed values deny consent. Persistence requires `cookiePersistEnabled: true` **and** an explicit full consent integration; enabling the setting alone cannot write cookies. Runtime withdrawal suppresses subsequent sends and clears the tracker persistence cookie; already transmitted requests cannot be recalled. Basic sends only minimal pageviews. `heartbeatEnabled: false` suppresses heartbeats. Runtime consent requires the matching updated backend tracker bundles.

`footerBadgeEnabled: true` enables the footer attribution. Badge requests carry no page URL or Referer; exclusions and disabled connections suppress it too.

## Backend deployment prerequisite

Deploy the matching Hub migration `2026_09_17_000004_allow_plugin_site_subpaths` first: account-owned site subpaths receive separate bindings while anonymous trials remain host-limited. Its rollback requires resolving duplicate hosts first. The matching Analytics changes add migration `2026_09_17_000004_add_plugin_platform`, platform-aware fixed challenge routing, same-origin validated TYPO3 billing returns and runtime tracker consent. Deploy that backend and its rebuilt tracker assets **before** enabling TYPO3 onboarding. Older backends are rejected when they do not acknowledge the TYPO3 platform; no WordPress identity is silently assumed.

Trusted installation-wide overrides live in `config/system/additional.php`, not form fields or site exports:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['prelumen_analytics']['installationApiUrl'] = 'https://analytics.prelumen.com';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['prelumen_analytics']['backendReturnPath'] = '/typo3/';
```

For TYPO3 installed under `/cms`, use `/cms/typo3/`. Return paths must remain on the connected site's HTTPS origin and end in `/typo3/`; a different backend hostname or custom entry-point name is not supported by this contract. Billing returns to the native backend entry point; reopen Prelumen to retrieve authoritative status.

## Tests

```sh
composer install
vendor/bin/phpunit -c phpunit.xml.dist
vendor/bin/phpunit -c phpunit.functional.xml
```

Unit tests live under `tests/Unit`; functional tests boot TYPO3 with SQLite, extension DI/schema and real frontend requests/page cache. See `../../docs/TYPO3-PARITY.md` for the exact versions, browser evidence and release limitations. Existing DDEV harness: `tools/create-typo3-test-install.sh`; DDEV is not required for the functional suite.
