# Troubleshooting

## Licence status messages

These are shown on the SMTP Configuration screen and on the licensing screen in Contao → Settings.
See [`Licensing.en.md`](Licensing.en.md) for what each state means; this section focuses on what to do
about it.

| Message | What to do |
|---|---|
| No licence. Get one at v-t.one and enter the key below. | Enter a licence key in the Settings section and press "Verify and Activate Licence". |
| This licence key is not valid for this product's Free plan. | Confirm the key is intended for this product. Contact V-T.ONE if you believe this is incorrect. |
| This licence is no longer active for this installation. | Contact V-T.ONE about the state of this licence. Once resolved, "Update Licence" fetches the corrected state. |
| This licence is not issued for any domain configured on this installation. | Check the root pages' DNS field and `vtinnovations_smtp.domains` against the domain(s) the licence was issued for. Correct whichever is wrong, then press "Update Licence". |
| The stored licence could not be verified. Re-enter your licence key to restore it. | Enter the licence key again and activate it. This replaces the locally stored copy with a freshly verified one. |
| This licence predates the current format. Enter your key again to fetch an updated copy. | Enter the same key again and activate it; nothing else is required. |
| This build cannot verify licences: no verification key is present. | Reinstall the bundle from an official release. This is a build issue, not something a licence key can fix. |
| No domain is configured for this installation. | Set the DNS field on a root page, or add hostnames under `vtinnovations_smtp.domains` in `config/config.yaml`, then activate. |

## SMTP Configuration screen shows "not licensed"

The module points at Contao → Settings. Follow the activation guidance above; the module becomes
usable as soon as the installation reports a licensed state.

## The test e-mail fails when saving

The configuration is not saved unless the test succeeds. If it fails:

- Confirm the host and port are correct for the mail provider, and that the chosen encryption
  (none / STARTTLS / SSL-TLS) matches what that port expects. STARTTLS is typically port 587;
  implicit SSL/TLS is typically port 465.
- Confirm the server this Contao installation runs on can reach the SMTP host on that port —
  outbound connections on mail ports are sometimes blocked by hosting firewalls.
- If credentials are required, confirm the username and password are correct for that mail
  account. Leaving the password field blank keeps the previously saved password; it does not clear
  it.
- The exact error message returned by the mail transport is shown in the failure notice and is
  normally the most direct way to identify the problem (authentication rejected, connection
  refused, certificate problem, and so on).

## Cache clear fails after a successful test e-mail

The configuration itself is already saved to `.env.local` at this point; only the cache-clear step
failed. The failure notice includes the underlying error. Clear the cache manually:

```bash
bin/console cache:clear
```

If the failure mentions the PHP CLI binary could not be found, set it explicitly:

```yaml
# config/config.yaml
vtinnovations_smtp:
    php_binary: '/path/to/php'
    process_timeout: 120
```

The correct path is the PHP command-line binary on the server (not the PHP-FPM or web-server SAPI
binary). Common locations include `/usr/bin/php`, a version-specific path such as
`/usr/bin/php8.3`, or a control-panel-specific path under `/opt/plesk/php/…` or
`/opt/cpanel/ea-php…/root/usr/bin/php`. Ask your hosting provider if none of these apply.

## A domain with non-ASCII characters will not activate

Install or enable `ext-intl`. Without it, such a hostname cannot be normalised to the exact
spelling a licence is signed for, and activation for that hostname is refused rather than guessed
at.

## Sender or test-recipient address is empty when I return to the screen

This is expected: those two fields are used only to send the verification e-mail and are not
persisted. Re-enter them each time the form is saved. See
[Known limitations](../README.en.md#known-limitations) in the main README.

## `bin/console vtinnovations:smtp:disable` says the installation is not available

The command requires a licensed installation, the same as every other operation in this bundle.
Resolve the licence status first (see the table above), then run the command again.

## Related documents

- [`README.en.md`](../README.en.md) — installation, configuration and feature overview.
- [`Licensing.en.md`](Licensing.en.md) — licence states and lifecycle in detail.
- [`Security.en.md`](Security.en.md) — the security assurance model.
