# Security model

This document describes, at a high assurance level, the security-relevant controls that
`vtinnovations/smtp-bundle` actually implements. It deliberately stops short of explaining how
those controls are implemented — internal class or method names, request/response formats,
cryptographic constructions, key material, storage schemas and validation order are all out of
scope for public documentation, regardless of whether some of that material is technically
observable on the wire. Publishing that level of detail would mainly serve as a map for defeating
the mechanism, not as information an administrator needs to operate it.

The controls below are grouped by how strong a statement can honestly be made about them.

## Guaranteed by the code

- **Access control.** The SMTP Configuration backend module is restricted to backend
  administrators; any other backend user is refused with an explicit access-denied message.
  Licence management is displayed only on Contao's `tl_settings` screen, which Contao itself
  restricts to administrators by default. The activate, update and remove actions behind it are a
  separate backend-scoped route that does not rely on that: it re-checks that the caller is an
  authenticated Contao administrator and that the POST carries a valid Contao request token, and
  refuses with 403 otherwise.
- **Independent permission enforcement per operation.** Viewing the configuration screen, sending
  the mandatory test e-mail, persisting the configuration, clearing the cache, and the console
  command that removes the configuration each check the installation's entitlement on their own,
  at the point they run. None of them assumes that an earlier check elsewhere already covered
  them; removing or bypassing any single check does not open the others.
- **Server-side entitlement enforcement.** Entitlement is decided entirely on the server, from
  data stored outside the browser's reach. No client-supplied value influences whether an
  operation is permitted.
- **Authenticated and integrity-checked licence data.** Licence data received from the licensing
  service — during activation, refresh, or a service-initiated update — is cryptographically
  signed and is checked for authenticity and integrity before any of its content is used to decide
  anything. The same check is repeated every time the stored licence is read, not only when it
  first arrives, so a locally altered or substituted file is detected rather than trusted.
- **Private storage.** The stored licence, its supporting integrity data, and the bundle's other
  bookkeeping live under `var/`, outside the public web root. Mailer credentials are written only
  to `.env.local`, in the same location Contao itself expects application secrets to live, never
  to the browser and never to Contao's own regular configuration file.
- **Trusted HTTPS communication.** All outbound licence-related network calls use TLS with
  certificate and hostname verification enabled, follow no HTTP redirects, and are bounded by
  short connection and total-duration timeouts, so a slow or misbehaving endpoint cannot stall a
  backend request indefinitely.
- **Safe failure behaviour.** An unreachable licensing service, a timeout, or an unreadable
  response leaves the installation's existing entitlement untouched — none of those situations is
  treated as a verdict on the licence. Only an answer that positively and verifiably refuses a key
  withholds entitlement, and even then the previously stored, authenticated licence record is not
  deleted, only its effect is withheld.
- **Redacted logging.** Where this bundle logs anything about a licence exchange, the log entry
  records outcome, an internal category, and timing — never a licence key, a signature, a nonce, a
  raw request or response body, or anything derived from them. This is checked by an automated
  test suite against both actual logged output and the source code itself, so a future change that
  reintroduces sensitive log content is caught rather than shipped.
- **Secret handling.** Mailer credentials never appear in the browser after being saved (the
  password field is always rendered blank), and the licence key is never rendered into any HTML
  page produced by this bundle.

## Conditional on the environment

- Signature verification for licence data requires `ext-sodium`. Without it, this bundle does not
  treat any licence as valid — a missing verification capability is a refusal, not a pass-through.
- Normalising a non-ASCII (internationalised) hostname to the exact spelling a licence is signed
  for requires `ext-intl`. Without it, such a hostname cannot be activated against.

## Best-effort / verified only for the scope stated

- **Transactional persistence of the licence record (licence storage only).** When the installed
  licence record is replaced — after activation, a refresh, or a service-initiated update — the
  new record is verified once written to disk, and rolled back to the previous record
  automatically if that verification does not succeed. This guarantee is specific to the licence
  record and its integrity data; it is not a general filesystem-transaction facility for the rest
  of the server.
- **Maintenance mode during cache clearing.** While the bundle's own cache-clear operation runs,
  the site is placed behind a maintenance page, which is always removed afterwards — including
  when the underlying cache-clear process fails.
- **Post-operation verification.** After saving a mailer configuration, or after installing an
  updated licence record, the result is read back and confirmed before the operation is reported
  as successful.

## Limitations

- No statement in this document should be read as a claim that any part of this mechanism is
  impossible to defeat. It describes the controls that exist, in restrained and factual terms, not
  an absolute guarantee.
- The transactional and rollback behaviour described above applies to this bundle's own licence
  storage; it does not extend to arbitrary files or to the wider hosting environment.
- This document does not, and will not, describe exact request or response formats for licensing
  operations, the algorithms or key material involved, the order in which checks are performed, or
  any other detail that would function primarily as a guide to bypassing the mechanism rather than
  operating it.

## Related documents

- [`README.en.md`](../README.en.md) — installation, configuration and feature overview.
- [`Licensing.en.md`](Licensing.en.md) — administrator-level licensing behaviour and states.
- [`Troubleshooting.en.md`](Troubleshooting.en.md) — practical steps for common status messages and
  failures.
