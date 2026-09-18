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

---

## Follow-up, 2026-09-18 — one defect the audit could not have caught

Re-reading `public/.htaccess` while answering "is this ready to deploy" turned up
a fault that **no runtime test in this repository can reach**: the PHP dev server
never reads `.htaccess`, so an Apache-only rule passes every live suite here and
then breaks the app the first time it is deployed.

`public/.htaccess` set the four security headers unscoped. Its own comment said
they were a backup "for Apache's own static-file responses", but as written they
also applied to PHP responses — and mod_headers' `always` writes into
`err_headers_out`, which Apache **merges** with what PHP already sent rather than
replacing it. Measured on Apache 2.4 with a stand-in for the viewer route:

```
X-Frame-Options: DENY                              <- .htaccess
X-Frame-Options: SAMEORIGIN                        <- PHP, the framed route
Content-Security-Policy: ... frame-ancestors 'none' ...   <- .htaccess
Content-Security-Policy: ... frame-ancestors 'self' ...   <- PHP
```

A browser resolves conflicting `X-Frame-Options` as deny, and intersects multiple
CSP headers so the stricter `frame-ancestors` wins. **The in-app document viewer
(FR-41) would therefore have rendered as an empty box on every Apache
deployment** while working perfectly in development. Verified in Chrome against a
local Apache: the same-origin iframe was refused before the fix and rendered
after it.

Fixed by scoping the header block to static files with `<FilesMatch>`, which is
what the comment always said it was for. Static assets still carry all four
headers from Apache; PHP responses state their own policy, which the front
controller does on every single response — both re-measured through Apache.

Two static guards were added to `suite_security_audit.sh` (§13) so this cannot
come back unnoticed: every `Header always set` must sit inside a `FilesMatch`,
and the project-root `.htaccess` must still fail closed.

**The lesson for the rest of this audit:** its scope note above says Apache was
not exercised, and this is what that limitation was worth. Anything else in the
Apache and HTTPS layer is still unverified.

## Apache verification, 2026-09-18

The gap above is now substantially closed. The app was served through the
machine's real Apache (XAMPP's, mounted via a directory junction into `htdocs`
so no XAMPP configuration was edited, removed afterwards) and the suites were
re-run against it. `common.sh` now honours `CCIS_BASE`, so any suite can be
pointed at any server:

```
CCIS_BASE=http://localhost/ccis bash docs/qa/suites/suite_security_audit.sh
```

| Suite | Dev server | Apache |
|---|---|---|
| `suite_security_audit` | 32 / 0 | **32 / 0** |
| `suite_document_viewer` | 33 / 0 | **33 / 0** |
| `suite_rbac` | 141 / 0 | **141 / 0** |
| `smoke_postcommit` | 49 / 0 | **49 / 0** |
| `suite_navigation` | 159 / 0 | 139 / 20 — see below |

Measured directly through Apache, beyond the suites:

- The framed route sends exactly one `X-Frame-Options: SAMEORIGIN` and one CSP
  with `frame-ancestors 'self'` — the fix above, confirmed on the real thing.
- Static assets still carry all four headers from `.htaccess`.
- `/assets/css/` gives 403, not a directory listing.
- `.env`, `app/`, `storage/uploads/` and `database/seed.sql` are unreachable.
- Sign-in, the monitoring board, the document viewer page and asset loading all
  work under a subfolder mount, which confirms the `BASE_URL`-from-`SCRIPT_NAME`
  derivation rather than just assuming it.

### Two things this run corrected in the tests themselves

**The `§13` Apache checks had been silently skipping.** Their relative paths were
one level short (`docs/qa/suites/../..` is `docs/`, not the repo root), so the
file-existence guards short-circuited and the avatar-store assertion was an `ls`
of a directory that does not exist — counting zero files and passing. A vacuous
pass is worse than a failure, and it only came to light because the one path
without a guard failed out loud on Apache. Paths now resolve from a single
`$REPO`, and a missing file fails instead of skipping.

**`suite_document_viewer` §4 was asserting the environment, not the app.** It
demanded 404 for `/documents/{id}.pdf`, which is true only on the dev server
(it answers extension-bearing URIs from disk before the router runs). Apache
routes it, the `{id}` is `(int)`-coerced, and the viewer page for document 1 is
rendered — `text/html`, with ownership still enforced (faculty2 404, anonymous
302, verified). The assertion now checks what must hold on both: never raw file
bytes, and never a way around ownership.

`suite_navigation`'s 20 failures are all one class — it greps root-anchored
hrefs (`href="/notifications"`), which correctly appear as `/ccis/notifications`
under a subfolder mount. A real deployment points DocumentRoot at `public/` and
is root-mounted, so the suite applies as written; the assumption is now stated
in its header. Its last hard-coded absolute path was also replaced with one
resolved from the script's own location.

### Still not covered

HTTPS end-to-end, HSTS and the `Secure` cookie flag: this ran over plain HTTP.
A **root-mounted** Apache is also still unverified — the junction mounts the app
at `/ccis`, and while that exercises `.htaccess`, mod_rewrite and the header
stack, it is not the document-root layout a deployment uses.

