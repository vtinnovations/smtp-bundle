# vtinnovations/smtp-bundle

A Contao 5 bundle that gives administrators a backend screen for configuring the site's outgoing
mail transport. It builds and tests an SMTP connection, persists it to `.env.local` as
`MAILER_DSN`, and clears the application cache safely afterwards. The bundle is licensed and
activation against V-T.ONE's licensing service is required before its functionality becomes
available.

**[Deutsche Version dieser Datei](README.md)**

## Status

This is the current, native implementation of the bundle — not a placeholder, a partial port or a
planned future phase. All functionality described below is implemented and exercised by an
automated PHPUnit test suite. No claim is made here about production usage history beyond what the
repository itself demonstrates.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Backend access and navigation](#backend-access-and-navigation)
- [Licensing](#licensing)
- [SMTP configuration](#smtp-configuration)
- [Console command](#console-command)
- [Feature status](#feature-status)
- [Security model](#security-model)
- [Runtime directories](#runtime-directories)
- [External communication](#external-communication)
- [Logging](#logging)
- [Deployment](#deployment)
- [Cache clearing](#cache-clearing)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [License and copyright](#license-and-copyright)

## Requirements

| | |
|---|---|
| PHP | 8.2 or later |
| Required extensions | `ext-json`, `ext-sodium` (licence-signature verification) |
| Recommended extension | `ext-intl` — without it, internationalised (non-ASCII) hostnames cannot be normalised to the spelling a licence is signed for, and such a domain will not activate |
| CMS | Contao 5.3 or later (`contao/core-bundle: ^5.3`). Continuous integration additionally verifies the bundle against Contao 5.6 and 5.7. |
| Framework components | Symfony 6.4 or 7.x (`symfony/dotenv`, `event-dispatcher`, `http-client`, `http-foundation`, `http-kernel`, `mailer`, `process`), `doctrine/dbal` ^3.6 or ^4.0 |
| Manager plugin | `contao/manager-plugin` ^2.0 |

## Installation

```bash
composer require vtinnovations/smtp-bundle
```

The bundle registers itself with Contao Manager (`Vtinnovations\SmtpBundle\ContaoManager\Plugin`)
and loads after the Contao core bundle. It adds no database tables or columns, so no schema
migration is introduced by this package specifically. After installing or updating it, clear the
application cache so the container picks up its services and routes:

```bash
bin/console cache:clear
```

Running `bin/console contao:migrate` afterwards is still good routine practice for a Contao
installation in general, but is not required by this bundle on its own.

## Backend access and navigation

Everything this bundle exposes is backend-only; it adds no frontend module, content element or
page.

| Location | Screen | Who can use it |
|---|---|---|
| Contao → **Settings** | "SMTP Konfigurator Licence management" section (current state, licence-key field, and the Activate / Update / Remove actions) | Users with access to `tl_settings` (administrators, by Contao's own default); the actions themselves are additionally restricted to administrators |
| Contao → **System** → **SMTP Configuration** | Backend module for the mailer itself (host, port, encryption, credentials, sender, test recipient) | Backend administrators only — the module explicitly checks `BackendUser::isAdmin` and denies access to any other user |

Licence management is deliberately kept in Settings rather than in the SMTP module itself: several
V-T.ONE packages installed side by side can be administered from one place, in the same way as
other Contao-wide configuration.

## Licensing

An activated licence is required before the SMTP Configuration module, the mailer test/send path
and the cache-clear operation become usable. Attempting any of those operations without a granted
licence is refused — the check is repeated at each operation itself, not only when the backend
screen is rendered.

### Licence model

This build is issued under V-T.ONE's **Lifetime Free** licensing model for this product: a valid
key activates the package permanently, with no expiry date and no paid tier to upgrade to. A key
that is genuinely signed but issued for a different model — a time-limited, trial or paid licence —
is refused for this product even though it is authentic; there is no local fallback to a lesser
entitlement in that case. The user interface still shows a "Free" / "Pro" plan label, kept for
consistency with other V-T.ONE products that do offer a paid tier, but no paid licence is currently
issuable for this particular product.

### Domain binding

A licence is bound to one or more exact hostnames. `example.com`, `www.example.com` and
`shop.example.com` are three different identities; a licence issued for one does not cover the
others, and no suffix, parent-domain or `www.`-equivalence matching is performed. Several domains
on one installation are supported — one match between the installation's configured hostnames and
the licence's bound hostnames is enough to activate.

The hostnames this installation claims to be are taken only from configuration, in this order,
never from an incoming request:

1. the **DNS** field of the site's root pages;
2. `vtinnovations_smtp.domains` in the application configuration;
3. the router's configured default host, as a last resort.

To set the domains explicitly:

```yaml
# config/config.yaml
vtinnovations_smtp:
    domains:
        - 'example.com'
        - 'www.example.com'
```

### Activating, refreshing and removing a licence

In Contao → Settings → "SMTP Konfigurator Licence management":

- **Enter a key and press "Verify and Activate Licence"**. The installation verifies it against the
  licensing service immediately.
- **Press "Update Licence"** to fetch an up-to-date copy of the licence already on file, without
  waiting for the automatic daily re-check — useful right after a renewal or a change to the
  configured domains. The key does not have to be re-entered.
- **Press "Remove Licence"** (and confirm) to delete the stored licence and return the installation
  to its unlicensed state immediately.

Update and Remove only appear while a licence is actually active. The key field is always rendered
empty: the stored key is never written back into the page.

The licence key is never written to Contao's regular configuration storage (`localconfig.php`); it
lives in the bundle's own private state under `var/` (see [Runtime directories](#runtime-directories)).

### Licence status

The headline at the top of the section reports the installation's current, freshly evaluated state.
When a licence is active, the line underneath it spells out the package, the matched domain, the
full set of licensed domains, the domain allowance, the validity dates, when the licence was last
confirmed against the licensing service, and the record's revision. The licence key itself is
deliberately not part of that line.

The possible states, in the project's own terminology:

| Status shown | Meaning |
|---|---|
| Licence active. All features unlocked. | Licensed and usable. |
| No licence. Get one at v-t.one and enter the key below. | No key has ever been entered, or none is currently stored. |
| This licence key is not valid for this product's Free plan. | The key is genuine but belongs to a different licensing model (time-limited, trial or paid) than this product accepts. |
| This licence is no longer active for this installation. | The licensing service explicitly withdrew this key. The record is kept, not deleted, so a future re-activation does not require re-entering anything already on file. |
| This licence is not issued for any domain configured on this installation. | None of this installation's configured hostnames appears in the licence. |
| The stored licence could not be verified. Re-enter your licence key to restore it. | The stored record failed its integrity check (for example, it was edited, or its supporting data no longer matches). |
| This licence predates the current format. Enter your key again to fetch an updated copy. | The stored record uses an older document layout; activating the key again fetches a current one. |
| This build cannot verify licences: no verification key is present. | A build-level problem, not a licensing one — reinstall from an official release. |
| No domain is configured for this installation. | Set the DNS field on a root page, or `vtinnovations_smtp.domains`, before activating. |

A background re-check runs at most once a day while a key is on file, so a renewal or a domain
change on the licensing side is normally picked up without an administrator doing anything.

For further detail on how entitlement is evaluated and communicated, see
[`Documentation/Licensing.en.md`](Documentation/Licensing.en.md).

## SMTP configuration

Contao → System → **SMTP Configuration**:

1. Enter the SMTP host, port, encryption (none, STARTTLS, or SSL/TLS), and — optionally —
   credentials.
2. Enter a sender address and a test-recipient address.
3. Save. The bundle sends a real test e-mail through the entered settings **before** anything is
   persisted; the configuration is written to `.env.local` and the cache is cleared only if that
   test succeeds.

If the password field is left blank on an update, the previously stored password is reused rather
than cleared — only the other fields need to be re-entered to change host, port or encryption.

The sender address and test-recipient address are used only to send the verification e-mail; they
are **not** persisted and are not pre-filled on the next visit to the screen (see
[Known limitations](#known-limitations)).

Only SMTP and SMTPS connections are supported by this screen (`smtp://` for no encryption or
STARTTLS, `smtps://` for implicit TLS). There is no interface here for API-based transactional
e-mail services.

When the installation is not licensed, this screen shows that state and points at Settings instead
of the configuration form.

## Console command

```bash
bin/console vtinnovations:smtp:disable [--clear-cache]
```

Removes `MAILER_DSN` from `.env.local` so Contao falls back to its own default mailer. Like every
other protected operation in this bundle, it requires a licensed installation and refuses to run
otherwise. Without `--clear-cache`, run `bin/console cache:clear` afterwards to apply the change.

## Feature status

| Feature | Status | Notes |
|---|---|---|
| SMTP/SMTPS mailer configuration | Available | Host, port, STARTTLS/SSL, optional credentials. |
| Connection test required before saving | Available | A real test e-mail must succeed first. |
| Password reuse on update | Available | Leave the password field blank to keep the existing one. |
| Automatic cache clear and warmup on save | Available | Behind a maintenance page, always removed afterwards. |
| Disable mailer via console | Available | `vtinnovations:smtp:disable`. |
| Licence activation, refresh, removal | Available | Via Contao → Settings. |
| Multi-domain licence binding | Available | One matching configured hostname is enough. |
| Automatic daily licence re-check | Available | Silent; existing state is untouched on failure. |
| Server-initiated licence updates | Available | The licensing service can push a change to this installation. |
| Free/Pro plan distinction in the interface | Conditional | The label exists for product-family consistency; no paid licence is currently issuable for this product. |
| Trial licences | Not applicable | This product's licensing model has no trial state. |
| Fallback to Free on an expired or incompatible licence | Not applicable | No such fallback exists; an incompatible or non-matching licence is refused outright. |
| API-based mailer transports (e.g. third-party e-mail sending APIs) | Not available | The configuration screen builds SMTP/SMTPS connections only. |
| Frontend or content-element integration | Not applicable | The bundle is backend-only. |
| Persisted sender/test-recipient address | Limited | Used only to send the verification e-mail; not saved between visits. |

## Security model

This is a high-level description of the controls actually implemented; it intentionally does not
describe how they are implemented internally.

**Guaranteed by the code:**

- Every protected operation — viewing or saving the SMTP configuration, sending the test e-mail,
  clearing the cache, and the disable console command — independently checks the installation's
  entitlement before doing anything, rather than relying on a single upstream gate.
- The SMTP Configuration module is restricted to backend administrators.
- Mailer credentials are written only to `.env.local`, never to the browser or to the regular
  Contao configuration storage.
- Licence-related network exchanges use TLS with certificate and hostname verification, follow no
  redirects, and are bounded by short timeouts.
- An unreachable or erroring licensing service leaves the installation's existing entitlement
  exactly as it was; only an explicit refusal from the service withholds entitlement, and even then
  the stored licence record itself is not deleted.
- The stored licence record's authenticity is re-checked on every read, not only when it is first
  received, so a file edited or substituted on disk is detected.
- Operational logs record outcome, timing and category information only. Licence keys, signatures
  and request/response bodies are never written to logs — enforced by an automated test suite.

**Conditional on the environment:**

- Signature verification depends on `ext-sodium` being available; the bundle refuses to treat any
  licence as valid if it is not.
- Automatic hostname normalisation for non-ASCII domains depends on `ext-intl`.

**Best-effort / verified only for the scope stated:**

- Writing an updated licence record to disk is verified after the write and rolled back
  automatically if the result does not check out; this guarantee applies to the licence record
  specifically, not to arbitrary filesystem operations elsewhere on the server.
- Cache clearing places the site behind a maintenance page for the duration of the operation and
  always removes it afterwards, including on failure.

**Limitations:**

- No security or licensing mechanism is claimed to be impossible to defeat; the statements above
  describe the controls that exist, not an absolute guarantee.
- This document does not enumerate the exact request formats, cryptographic constructions or
  validation order used, by design.

A deeper description of this model — still at the same administrator level — is in
[`Documentation/Security.en.md`](Documentation/Security.en.md).

## Runtime directories

| Path | Purpose |
|---|---|
| `.env.local` | Holds `MAILER_DSN` once the mailer is configured. |
| `var/vtinnovations-smtp/` | The bundle's private state: the stored licence record and its integrity data, replay bookkeeping, and the remembered licence key and activation status. Not part of the public web root. |
| `var/maintenance.html` | Created only for the duration of a triggered cache clear, then removed. |

## External communication

During licence activation, refresh, the daily background re-check, and when the licensing service
pushes a change to this installation, the bundle exchanges cryptographically signed, authenticated
data with a trusted HTTPS service operated by V-T.ONE. Separately, minimal usage signals (which
product and domain are in use) are sent to the same operator; these do not affect entitlement and a
failure to send them never affects anything an administrator sees. No licence-related secret is
ever sent to, or exposed by, the browser, and none of this traffic is written to the application
log.

## Logging

Operational logging for licence exchanges and licence pushes records the outcome, the operation
type, timing and an internal category code — never a licence key, a signature, a raw request or
response body, or anything derived from them. This is enforced by an automated test suite that
checks both runtime behaviour and the source code itself.

## Deployment

```bash
composer require vtinnovations/smtp-bundle
bin/console cache:clear
```

There is no additional deployment step specific to this bundle beyond the standard Contao
cache-clear step above.

## Cache clearing

The SMTP Configuration screen clears and warms up the cache automatically after a successful save,
using:

```bash
bin/console cache:clear --no-warmup --env=prod --no-interaction
bin/console cache:warmup --env=prod --no-interaction
```

The same operation is available from the console:

```bash
bin/console vtinnovations:smtp:disable --clear-cache
```

If the PHP CLI binary cannot be located automatically on your host, set it explicitly:

```yaml
# config/config.yaml
vtinnovations_smtp:
    php_binary: '/path/to/php'
    process_timeout: 120
```

## Testing

```bash
vendor/bin/phpunit
```

The suite (`tests/Unit`) covers the mailer configuration path, licence activation/refresh/removal,
signed-record verification, the public update endpoint, cache clearing, and log-content redaction.
No test contacts the real licensing service; every remote call is mocked. This test suite was not
executed as part of producing this documentation, since no PHP/Composer environment was available
in the environment used to audit the repository; the commands above are exactly what the project's
own CI configuration runs.

## Troubleshooting

See [`Documentation/Troubleshooting.en.md`](Documentation/Troubleshooting.en.md) for guidance on the
licence-status messages, mailer test failures, and PHP-binary detection issues.

## Known limitations

- The sender address and test-recipient address entered on the SMTP Configuration screen are used
  only to send the verification e-mail and are not persisted; they must be re-entered every time
  the form is saved.
- The configuration screen supports SMTP/SMTPS connections only; there is no built-in interface for
  API-based transactional e-mail services, despite such services being usable with Contao's
  underlying mailer component through manual configuration.
- Without `ext-intl`, a hostname containing non-ASCII characters cannot be normalised to the
  spelling a licence is signed for, and such a domain will not activate.
- The SMTP Configuration module is available to backend administrators only; there is no
  finer-grained permission for non-administrator backend users.
- This build's licensing model supports one plan (Lifetime Free) for this product; the "Pro" label
  present in the interface is not currently reachable for this product.

## License and copyright

Copyright © 2026 VT Innovations Team. Licensed under the GNU Lesser General Public License v3.0
or later (LGPL-3.0-or-later). See [`LICENSE`](LICENSE) for the full text.

---

**[Deutsche Version dieser Datei](README.md)** · [Licensing](Documentation/Licensing.en.md) ·
[Security](Documentation/Security.en.md) · [Troubleshooting](Documentation/Troubleshooting.en.md)
