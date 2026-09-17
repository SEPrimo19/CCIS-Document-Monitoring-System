# Security audit and bug sweep — 2026-09-17

Run against a live instance at `http://localhost:8000` with the seed data in
place, at commit `8537369`, covering FR-1..FR-41.

This supersedes nothing: `SECURITY-FINDINGS-2026-07-24.md` is the earlier review
and is stale (see the "Stale documents" note in `CLAUDE.md`). The functional
audit in `docs/qa/RESULTS-2026-09-14.md` remains the record for behaviour; this
one is about whether the system can be made to misbehave.

## Method

Two halves, both evidence-based:

- **Static** — the route table (60 routes), every SQL call site, every `<?= ?>`
  in the views, the session and CSRF primitives, the upload validators, the
  configuration's failure mode, and the dev server's own log for the whole day
  (8,759 lines).
- **Dynamic** — roughly 150 requests as four principals (anonymous, Secretary,
  faculty1, faculty2) probing authentication, authorization, CSRF, session
  handling, injection, traversal, upload validation, privilege escalation
  through POST bodies, and response headers.

The dynamic half is kept as `docs/qa/suites/suite_security_audit.sh` so it can
be re-run after any change. It is safe against real data: every probe is either
read-only or an operation the system is expected to refuse, and each refusal is
confirmed by reading the state back rather than by trusting a status code. It
does not exercise a successful upload, and it throttle-tests an address that
cannot exist so the seed logins are never locked out.

## Result

**28 of 29 assertions passed on the first run.** The one failure was the version
banner below, found by the audit and fixed in the same pass.

Nothing was found that lets one account reach another account's documents,
escalate its own role, forge a state change, or read anything from disk that the
route did not intend to serve.

### What held up under probing

| Control | Evidence |
|---|---|
| Authentication | 25 protected routes, all 302 to `/login` for an anonymous caller |
| Vertical authorization | 16 Secretary-only screens, all 403 for Faculty — including the review POST |
| Horizontal authorization | faculty2 gets 404 on faculty1's viewer, download, raw bytes and submission detail; the owner still gets 200 |
| Enumeration | "not yours" and "does not exist" return the identical response on the document and avatar routes |
| CSRF | absent, forged, and *valid-but-from-another-session* tokens all rejected; account states unchanged across all three |
| Session | id rotated on sign-in (fixation closed), `HttpOnly`, `SameSite=Lax`, `use_strict_mode=1`, 30-minute idle timeout, logout invalidates |
| Brute force | locked out after 5 failures in 15 minutes, keyed on email+IP |
| SQL injection | 36 payloads across 6 parameters: no 5xx, no change in row counts. Every query is a prepared statement; no interpolation anywhere |
| XSS | the search term comes back escaped; the one unescaped echo in the app is `DocxHtml` output, whose every tag it writes itself |
| Traversal | 9 payloads refused, including `%2F`-encoded traversal on the media and avatar routes |
| Source exposure | `.env`, `config/`, `app/`, `database/seed.sql`, `.git/config` and `storage/uploads/` are all unreachable over HTTP |
| Upload validation | `.exe`, a renamed PDF and an oversized file rejected with no version stored; an SVG, a PHP-in-PNG, a mislabelled JPEG, an over-large and an empty image all rejected with nothing written to the avatar store |
| Privilege escalation | forging `role_id`, `role_name`, `status` and `program_id` in the profile POST changes nothing — those columns are absent from the self-service statement |
| Headers | `nosniff`, `DENY`, `no-referrer` and a `default-src 'self'` CSP on every page; only `/documents/{id}/view` relaxes to `SAMEORIGIN`, and only on its own response |
| Error handling | 5 malformed URLs leak no PHP error, file path or SQL detail |
| Runtime health | 8,759 server-log lines for the day: no PHP warning, notice or deprecation, and no 5xx |

## Findings

### Fixed in this pass

**S-1 — LOW — `X-Powered-By` disclosed the exact PHP version.**
Every response carried `X-Powered-By: PHP/8.2.12`, which hands an attacker the
CVE list to start from before they have probed anything. `header_remove()` in the
front controller, so the posture does not depend on a `php.ini` the deployment
may not control. `expose_php=Off` remains the other half and is already in
`docs/SECURITY.md`.

**B-1 — LOW — an empty upload was reported as being over the size limit.**
`$size <= 0 || $size > MAX` shared one message, so a 0-byte file told the faculty
member "File exceeds the 10 MB limit" and sent them off to shrink a document that
was already empty. The avatar validator had always said this correctly; the two
now agree.

### Open, ranked

**B-2 — LOW — uploaded files are never deleted from disk.** Previously recorded;
re-measured today at **559 files / 187 MB against a handful of rows**, almost all
QA residue. Deleting a requirement cascades the database rows but nothing
unlinks the file. Harmless at demo scale, and the fix is a small reconciliation
script (list `document_files.stored_name`, delete what is on disk and not in the
table). Worth running once before the defence so the folder is not 187 MB.

**B-3 — LOW — the PDF preview cache has no eviction.** `storage/previews/` is
keyed on the source's size and mtime, so a re-uploaded document leaves its old
preview behind forever. 5 files / 2.9 MB today. Same class as B-2 and the same
script can handle both.

**S-2 — LOW — `.env.example` ships `APP_ENV=development`.** The configuration
itself is fail-safe (unset or unrecognised resolves to production), but the
documented first step is `copy .env.example .env`, so anyone following SETUP.md
for a real deployment starts in development, where raw exception text is shown
and `migrate.php` will drop and recreate all 13 tables. `docs/SECURITY.md` §3.2
does say to set production, but the default should not need a second document to
correct it. Local development genuinely needs the flag — the honest fix is to
ship the example commented out and have SETUP.md tell a local developer to
uncomment it.

**S-3 — INFO — `docs/SECURITY.md` §3.2 is now inaccurate.** It says setting
`APP_ENV=production` is what issues the session cookie with `Secure`. That is no
longer true: `Auth::boot()` derives `Secure` from the actual transport
(`$_SERVER['HTTPS']`), deliberately, so that production-over-plain-HTTP-localhost
does not break sign-in. The requirement (serve over HTTPS) is unchanged; the
stated mechanism is wrong, and someone following it could believe they have
`Secure` cookies when they are still on HTTP.

**B-4 — INFO — route ids are `(int)`-coerced, so several URLs address one
resource.** `/documents/1abc/download`, `/documents/+1/download` and
`/documents/1%2F..%2F2/download` all resolve to document 1. **This is not an
authorization bypass** — it was tested directly: the resolved id goes through the
same ownership check, and faculty2 still gets 404 on every variant. It matters
only if something is ever cached or rate-limited by URL string.

**B-5 — LOW — faculty page loads fire 3 unconditional reminder `INSERT…SELECT`
queries with no retention policy.** Previously recorded, unchanged.

## What this audit did not cover

- **No HTTPS.** Everything was tested over plain HTTP against the PHP dev
  server. Transport security, HSTS and the `Secure` cookie flag are deployment
  concerns and remain untested here (`docs/SECURITY.md` §3.2).
- **Apache is the intended production server**, and its own handling of static
  files, directory listings and `.htaccess` was not exercised — the dev server
  has different behaviour, notably that any URI containing a file extension is
  served straight from disk.
- **No load, concurrency or denial-of-service testing.** The optimistic lock on
  the review POST is covered functionally, not under real concurrency.
- **No dependency scanning** — there are no runtime Composer dependencies to
  scan, which is itself the reason this is a small attack surface.
- The **destructive QA suites** (`suite_profile`, `suite_review`,
  `suite_resubmit`, `suite_admin_crud`) were not run, because they write to the
  database and do not restore it. They need a clean reseed.
