# Licensing

This document describes, at the administrator level, how licensing works for
`vtinnovations/smtp-bundle`. It intentionally does not describe request formats, cryptographic
constructions, storage schemas or validation order — see the note at the end of this document.

## Why a licence is required

Every functional part of this bundle — the SMTP Configuration screen, sending the mandatory test
e-mail, writing and clearing the mailer configuration, and the console command that removes it —
is a protected operation. Each of these checks the installation's entitlement independently, at
the moment it runs, rather than relying on a single check somewhere upstream. Without a granted
licence, none of them do anything beyond reporting that the installation is not licensed.

## The licence model for this product

This build accepts one licensing model for this product: **Lifetime Free**. A licence that
verifies successfully for this product is, by construction, permanent and free of charge — there
is no expiry date to track and no paid tier to upgrade to for this particular product.

A key that is genuinely and correctly signed, but was issued under a different model — for
example a time-limited free licence, a trial, or a paid licence — is refused for this product in
exactly the same way a licence for the wrong product or the wrong domain would be. There is no
local downgrade or fallback computed for such a key; it simply does not activate this product.

The backend interface still contains a "Free" / "Pro" plan label. This exists because the same
licensing mechanism is shared across several V-T.ONE products, some of which do offer a paid tier.
For this product, no paid licence is currently issuable, so the "Pro" label is not something an
administrator of this bundle will encounter in practice.

## Domain binding

A licence authorises one or more exact hostnames. Matching is always exact:

- `example.com` does not cover `www.example.com`, and neither covers any subdomain.
- No suffix, parent-domain or `www.`-equivalence matching is performed anywhere in this process.
- A licence can authorise more than one hostname; if it does, activating on any single one of
  those hostnames is enough.

The hostnames this installation is considered to be are read only from configuration the site
owner controls — the DNS field of Contao root pages, the `vtinnovations_smtp.domains` setting, or,
only as a last resort, the framework's configured default host. They are never taken from the
`Host` header of an incoming request, because that value is something a visitor's browser
supplies, not something the installation's operator has vouched for.

```yaml
# config/config.yaml
vtinnovations_smtp:
    domains:
        - 'example.com'
        - 'www.example.com'
```

## Activation, refresh and removal

All three actions are performed from Contao → Settings → "SMTP Konfigurator Licence management":

| Action | How | Effect |
|---|---|---|
| Activate | Enter a licence key, then press **Verify and Activate Licence** | The key is checked against the licensing service immediately. On success, the installation is licensed from that point on. |
| Refresh | Press **Update Licence** | Fetches a current copy of the licence already on file, without waiting for the automatic daily re-check and without re-entering the key. Use this right after a renewal, or after changing which domains this installation serves. |
| Remove | Press **Remove Licence** and confirm | Deletes the stored licence and its bookkeeping. The installation returns to its unlicensed state immediately. |

Refresh and Remove are only offered while a licence is active. All three post to the package's own
backend-scoped action route, which independently requires an authenticated Contao **administrator**
and a valid request token before it does anything, and then redirects back to Settings so the
section re-renders from freshly evaluated state.

A background re-check also runs automatically, at most once per day, for as long as a key is on
file. It is silent: if it cannot reach the licensing service, or the service does not answer, the
installation's existing entitlement is left exactly as it was.

The licence key itself is never written to Contao's regular configuration file
(`system/config/localconfig.php` or its Symfony-based equivalent). It is kept in this bundle's own
private state under `var/`, outside the public web root.

## Administrator-visible states

The headline of the licensing section reports one of the following states, using the wording the
interface itself uses. When licensed, the detail line underneath adds the package, matched domain,
licensed domains, domain allowance, validity dates, last confirmation and record revision — never
the licence key.

| State | What it means for the administrator |
|---|---|
| **Licence active. All features unlocked.** | Fully licensed. All protected functionality is available. |
| No licence entered | Nothing has been activated yet, or a previously stored licence was removed. |
| Licence key not valid for this product's Free plan | The key is authentic but belongs to a licensing model this product does not accept (see [above](#the-licence-model-for-this-product)). |
| Licence no longer active for this installation | The licensing service explicitly withdrew this key. The underlying record is retained rather than deleted, so a later re-activation is not starting from nothing. |
| Licence not issued for any configured domain | None of this installation's configured hostnames appears among the hostnames the licence authorises. |
| Stored licence could not be verified | The locally stored licence failed an integrity check — for example because the file was altered, or associated data on disk no longer matches it. Re-entering the key restores it. |
| Licence predates the current format | The stored record uses an older internal document layout. Saving the key again fetches a current one; nothing is lost. |
| No verification key present | A problem with the installed build itself, not with any licence. Reinstalling from an official release resolves it. |
| No domain configured | The installation has no hostname to activate against yet. Set the root page's DNS field, or `vtinnovations_smtp.domains`. |

These are the actual states this product's licensing evaluation can report. States that do not
appear in this list — such as a trial period, or an expired paid licence falling back to a free
tier — do not exist for this product: there is no trial state, and there is no such fallback.

## Entitlement effects

| While licensed | While not licensed |
|---|---|
| SMTP Configuration screen shows the configuration form | SMTP Configuration screen shows a notice pointing at Settings |
| The test e-mail may be sent and the configuration saved | Saving is refused, whichever path is used to reach it |
| The cache-clear step that follows a successful save runs | Not reached |
| `bin/console vtinnovations:smtp:disable` runs | The command refuses to run and reports that the installation is not licensed |

## What is authenticated, at a high level

Licence data exchanged with the licensing service — on activation, on refresh, and when the
service pushes an update — is cryptographically signed and verified before anything from it is
trusted. An answer that does not verify, that is incomplete, or that does not match this
installation's identity is treated the same way a network failure would be: nothing changes. Only
an answer that positively and verifiably refuses a key withholds entitlement, and even that does
not delete the underlying record. This document does not describe the signature scheme, the exact
fields exchanged, or the order in which checks are performed — that detail is deliberately kept
out of public documentation, since publishing it would mainly help someone trying to defeat the
mechanism rather than an administrator operating it.

## Related documents

- [`README.en.md`](../README.en.md) — installation, configuration and feature overview.
- [`Security.en.md`](Security.en.md) — the broader security assurance model.
- [`Troubleshooting.en.md`](Troubleshooting.en.md) — what to do for each status message.
