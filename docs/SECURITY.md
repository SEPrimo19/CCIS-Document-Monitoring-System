# Security & Deployment Hardening — CCIS-DMS

**Project:** CCIS Document Monitoring System (CCIS-DMS) · Northwest Samar State University, CCIS
**Stack:** PHP 8.2 · MySQL/MariaDB · vanilla HTML/CSS/JS · plain-PHP MVC
**Purpose:** Record the security posture of the system, the findings of the Phase 3
auth review, what was remediated, and the steps required to deploy the system
securely (as opposed to the local XAMPP development configuration).

This document is the operational companion to the non-functional requirements in
`../../Design/02-non-functional-requirements.md` (password hashing, file-upload
validation, least-privilege, etc.). The manuscript's security / NFR discussion can
cite it.

---

## 1. Security posture (verified in the Phase 3 review)

Two independent reviews (code review + focused security review) of the
authentication and role-based-access-control layer confirmed the following are
correctly implemented — **no** SQL injection, cross-site scripting, or
insecure-direct-object-reference weakness was found:

- **SQL:** all database access uses native PDO prepared statements with bound
  parameters; `PDO::ATTR_EMULATE_PREPARES` is off. No user input is concatenated
  into SQL.
- **Output encoding:** every dynamic value rendered in a view passes through
  `htmlspecialchars` (PHP 8.2 encodes both quote styles by default).
- **Passwords:** hashed with bcrypt via `password_hash` / `password_verify`; the
  hash is never placed in the session or sent to the client.
- **Sessions:** the session ID is regenerated (`session_regenerate_id(true)`) on
  successful login (session-fixation defense); logout clears `$_SESSION`, expires
  the cookie, and destroys the session.
- **CSRF:** login is protected by a `random_bytes(32)` session-bound token compared
  with `hash_equals`.
- **Authorization:** every role-scoped route is guarded server-side; there is no
  path to an admin/reviewer/faculty page without the matching role. The acting user
  is always resolved from the session, never from a request-supplied ID.

### 1.1 Phase 4 self-service & notification surface (reviewed 2026-07-25)

A focused review of the later-added features (self-service profile/password, the
archive, the document-detail page, academic-period management, and notification
generation) found the surface sound — no SQL injection, stored XSS, cross-user
IDOR, privilege escalation, or CSP violation. Specifically confirmed:

- **Self-service profile (FR-5) cannot escalate privilege.** Role, account
  status and academic program are not self-editable: `User::updateOwnProfile()`
  names only `first_name/last_name/email`, and the acting user id comes from
  the session, never the request — a forged `role_id`, `status`, or `user_id` in
  the POST body has nothing to bind to (verified with a live escalation attempt:
  the row stayed byte-identical and admin routes still returned 403).
  `program_id` joined that list when requirement audiences started keying off it
  (FR-35): a self-editable program would have been an obligation-evasion path,
  letting a faculty member move themselves out of a requirement aimed at their
  program. It is assigned by the Secretary on the user form instead. Removing
  the old free-text `program_dept` column also closes **LOW-5** in
  `SECURITY-FINDINGS-2026-07-24.md` (its 80-character validation cap did not
  match the column width) by deleting the field the finding was about.
- **Password change** verifies the current password with `password_verify`
  before writing, bounds the new one in bcrypt's 72-**byte** window, rejects a
  NUL byte as a validation error (not a 500), requires it to differ from the
  current one, rotates the session id, and is CSRF-protected.
- **Email change** uses the same lowercase+trim normalisation as the login
  lookup and checks uniqueness against **all** accounts (including inactive),
  so a user cannot collide with or take over another login identity via case or
  whitespace.
- **Archive (FR-33/34) and document detail (FR-11)** enforce per-user access as
  a SQL predicate, not a post-filter: a Faculty user sees only their own rows,
  and a non-owner (or nonexistent) submission/file returns **404**, not 403, so
  ids cannot be enumerated. The download route matches this 404 stance.
- **Notification content** (publish-time assignment, deadline/overdue reminders)
  interpolates requirement titles only through bound parameters or `CONCAT` over
  a column reference, and every rendered value passes through `htmlspecialchars`.

Three residual items are **Low/Info** — none blocks the defense or local use, but
each is worth closing before a real deployment:

1. **Other sessions survive a password change.** `changePassword()` rotates only
   the current session id; a session established before the change (e.g. a cookie
   left on a shared campus PC) keeps working. Fix: add `users.password_changed_at`
   (or a session-token version), stamp it on change, copy it into the session at
   login, and have `Guard::requireAuth()` log out on mismatch (OWASP ASVS 3.3.1).
2. **Reminder dedup is application-level, not a DB constraint.** The generators
   dedup with `LEFT JOIN … IS NULL`, so two concurrent faculty page loads can
   each insert a same-day reminder. It is self-scoped (a user can only inflate
   their own rows) and bounded (7-day deadline window, 30-day overdue floor), so
   it is a self-DoS at worst. Fix: a `UNIQUE(user_id, type, submission_id,
   DATE(created_at))` via a stored generated date column, with the generators
   switched to `INSERT IGNORE`.
3. **Period deactivation has a wide blast radius.** Closing the active period
   empties every checklist, the review queue, and the monitoring board. It is
   Secretary-only, CSRF-protected, reversible, and audit-logged; treat it
   operationally as a scheduled end-of-semester action, and consider requiring a
   typed confirmation or reason (stored in the audit `details`).

---

## 2. Remediation log

Findings from the review are tracked in three buckets.

### Bucket A — code fixes (applied in development)

Correctness and hardening fixes applied to the shared auth plumbing so that every
feature built on top inherits them. See the commit that follows the Phase 3 build.

| # | Finding | Fix |
|---|---------|-----|
| 1 | `status = "active"` (double-quoted) breaks all logins under MySQL `ANSI_QUOTES` | Use single-quoted literal / bound parameter |
| 2 | Subfolder deploy 404s (inbound base stripped, outbound URLs root-absolute) | `BASE_URL` + `url()` / `asset()` helpers; all outbound URLs made base-relative |
| 3 | Authorization used a login-time session snapshot | `Guard::requireAuth()` re-validates `status` + role from the DB each request; deactivated/role-changed users lose access immediately |
| 4 | Stack traces could leak on uncaught exceptions | Global exception handler + env-gated `display_errors`; branded 500 page |
| 5 | Unauthenticated `/health` leaked DB/PHP versions and error text | `/health` gated to Administrator; raw errors hidden outside development |
| 6 | `logout` was a CSRF-able GET | Logout is now `POST` + CSRF token |
| 7 | Cookie missing `Secure`; no strict mode; no idle timeout | `Secure` under HTTPS/production; `session.use_strict_mode=1`; 30-minute idle timeout |
| 8 | Login timing enabled user enumeration | Dummy `password_verify` on the unknown-user path to equalize timing |
| 9 | Hash cost could not be raised without a mass reset | `password_needs_rehash` upgrade on successful login |
| 10 | No security headers | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and a conservative CSP, sent from PHP and mirrored in `.htaccess` |
| 11 | Default admin password committed & echoed | Seed password read from `ADMIN_PASSWORD` (dev default retained); password no longer printed |

### Bucket B — login-endpoint hardening

| Finding | Status |
|---------|--------|
| No brute-force protection on login | **Done.** Per-`(email + IP)` failed-attempt throttle: 5 failures within a 15-minute window block further attempts (generic "too many attempts" message — no user enumeration) until they age out of the window; a successful login clears the counter. Failed attempts are logged in a dedicated `login_attempts` table — they cannot go in `audit_log`, whose `user_id` is `NOT NULL` and FK-bound to `users`. Successful logins are recorded in `audit_log` (`action='login'`). |
| Default credentials with no forced rotation | **Pending** — "must change password on first login," to be built with the FR-5 change-password / profile screen in Phase 4. |

### Bucket C — deployment hardening (this document, §3)

Items that are correct for local XAMPP-over-HTTP development but **must** change for
any real deployment described as a secure system.

---

## 3. Deployment hardening checklist

> The development configuration (MySQL `root` / no password, HTTP on `localhost`,
> `display_errors` on) is acceptable **only** on an isolated developer machine. Do
> **all** of the following before exposing the system to real users.

### 3.1 Least-privilege database user

Do not run the application as MySQL `root`. Create a dedicated user limited to the
data-manipulation rights the app actually needs on the `ccis_dms` schema:

```sql
-- Run once as an administrative MySQL account.
CREATE USER 'ccis_dms_app'@'localhost' IDENTIFIED BY 'REPLACE-WITH-A-STRONG-SECRET';
GRANT SELECT, INSERT, UPDATE, DELETE ON ccis_dms.* TO 'ccis_dms_app'@'localhost';
-- No DDL/GRANT/FILE privileges: the app never creates tables or reads server files.
FLUSH PRIVILEGES;
```

Then point the app at it via `.env` (never commit real secrets):

```
DB_USER=ccis_dms_app
DB_PASS=REPLACE-WITH-A-STRONG-SECRET
```

Schema changes (migrations) are run separately, by an operator using a privileged
account — not by the running application.

### 3.2 Transport security (HTTPS)

- Serve exclusively over HTTPS (TLS certificate; on a campus deployment this may be
  an internal CA).
- Set `APP_ENV=production` so the session cookie is issued with the `Secure` flag
  (already wired in `Auth::boot()`).
- Add HSTS at the web server: `Strict-Transport-Security: max-age=31536000; includeSubDomains`.
- Redirect all HTTP to HTTPS.

### 3.3 Production PHP configuration (`php.ini`)

```ini
display_errors = Off
log_errors = On
expose_php = Off
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1     ; requires HTTPS
session.cookie_samesite = Lax
```

The application also sets `display_errors` off and registers an exception handler at
runtime (Bucket A #4), but the `php.ini` baseline is the authoritative backstop.

### 3.4 Accounts

- Change the default Secretary password (`secretary@nwssu.edu.ph`) immediately
  after the first deployment login; do not keep `Secretary@123`.
- `scripts/migrate.php` also seeds dev-only Faculty accounts
  (`faculty1@nwssu.edu.ph`, `faculty2@nwssu.edu.ph`, `faculty3@nwssu.edu.ph`),
  documented for local development only (`FACULTY_PASSWORD`, dev default
  `Faculty@123`) — the same caveat as the Secretary default applies: never
  deploy with these unchanged.
- Provision real accounts through the Secretary's user-management screen
  (FR-26); there is no public self-registration by design.
- **The Secretary role is privileged.** With two roles, that single account
  manages users, configures requirements, verifies documents, and reads the
  audit log. Give it to as few people as the office genuinely needs, and rely
  on the audit log (FR-30, FR-31) for attribution — every approval, return, and
  configuration change is recorded against the acting account. The last active
  Secretary can never be deactivated or demoted (enforced transactionally), so
  the college cannot lock itself out.

### 3.5 File uploads — **Done** (implemented in Phase 4)

Uploads are already hardened in code; nothing is outstanding here. Recorded for the
manuscript and for anyone auditing the deployment:

- **Stored outside the web root.** Files land in `storage/uploads/` (a sibling of
  `public/`, not inside it), so there is no URL that maps to an uploaded file. They
  are readable only through the authenticated download route, which re-checks the
  requesting user's role and ownership. No `php_flag engine off` workaround is
  needed because the directory is not web-reachable at all.
- **Detected type, not the extension.** `finfo` sniffs the real MIME type, and for
  Word documents that is backed by a structural check — `.docx` must be a genuine
  OOXML zip (`[Content_Types].xml` + `word/document.xml` both present) and `.doc`
  must begin with the OLE2 compound-file signature. `application/octet-stream` is
  never accepted, so a renamed binary cannot get through.
- **Size capped at 10 MB**, enforced before the file is stored, with the
  `post_max_size` overflow case detected separately so it reports a size error
  rather than a misleading "session expired".
- **Server-generated file names.** Stored as `sub{id}_v{n}_{random}.{ext}`; the
  client file name is only kept as a display label (length-capped to fit the
  audit-log column), never used as a path.

### 3.6 Web-server headers (Apache)

The app emits security headers from PHP so they apply under the built-in dev server
too; under Apache, `public/.htaccess` mirrors them (requires `mod_headers`). Confirm
`mod_headers` is enabled and `AllowOverride` permits the `.htaccess`.

### 3.7 DocumentRoot must be `public/`

This is the single highest-impact deployment mistake available. If the vhost's
`DocumentRoot` (or the folder dropped into `htdocs/`) is the **project root** rather
than `public/`, Apache will happily serve `.env` (database credentials),
`database/schema.sql`, the entire `app/` source tree, and every file in
`storage/uploads/` as static downloads.

- Set `DocumentRoot` to the `public/` directory, and nothing above it.
- A deny-all `.htaccess` now sits at the project root as a safety net. Apache only
  reads `.htaccess` from the DocumentRoot downward, so it is inert in a correct
  deployment and only takes effect in the mis-set case — where it fails the site
  closed (403 on everything) instead of leaking. **A site that 403s on every page is
  the symptom of a wrong DocumentRoot**, not of a broken application.
- Verify after deploying: requesting `/.env`, `/app/Core/Database.php`, and
  `/storage/uploads/` must all fail. Do this before go-live, not after.

### 3.8 Backups

The system is the only record of faculty compliance once it replaces the paper
process, and an uploaded document generally cannot be reproduced by the office.
Two things must be backed up **together**, or a restore yields database rows
pointing at files that no longer exist:

- The `ccis_dms` database (`mysqldump`, scheduled — daily is a reasonable baseline).
- The `storage/uploads/` directory.

Keep copies off the application server, verify a restore at least once (an untested
backup is not a backup), and set retention to cover at least one full academic
period so a problem noticed at the end of a semester is still recoverable.

### 3.9 Logging and rotation

`error_log` is the app's only log sink (used for best-effort failures such as audit
writes and notification creation, which are deliberately non-fatal). Point
`error_log` at a file the web-server user can write, outside the web root, and put
it under log rotation (`logrotate` on Linux, or a scheduled task on Windows) — an
unrotated log will eventually fill the disk and take the application down with it.
Log contents are operational, not user-facing: they may contain email addresses, so
treat the log directory as sensitive.

---

## 4. Notes

- The PHP built-in server (`php -S`) ignores `.htaccess`; that is why security
  headers are also sent from PHP. Apache is the intended production server.
- **HTTP→HTTPS redirection (§3.2) is a web-server configuration step, not something
  the application does.** The app sets the `Secure` cookie flag based on the actual
  transport, but it will still serve a page over plain HTTP if Apache lets the
  request through. The redirect (and HSTS) must be configured in the vhost.
- `scripts/migrate.php` is destructive — it drops and recreates every table. It
  refuses to run unless `APP_ENV=development`, and requires an explicit `--force`
  otherwise. `APP_ENV` unset resolves to production, so the refusal is the default.
- This document tracks the state of remediation and should be updated when Bucket B
  lands and when the system is first deployed.
