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

- Change the default administrator password immediately after the first
  deployment login; do not keep `Admin@123`.
- Provision real accounts through the admin user-management screen (FR-26); there is
  no public self-registration by design.

### 3.5 File uploads (relevant from Phase 4 onward)

The upload directory (`public/uploads/`) will receive faculty documents. Before that
feature ships:

- Store uploads **outside** the web root, or ensure the directory cannot execute
  PHP (an `.htaccess` with `php_flag engine off` / `RemoveHandler` for the uploads
  path, or an Apache `<Directory>` that disables script execution).
- Enforce the type allow-list (PDF, DOC, DOCX) by validating the **detected** MIME
  type (e.g. `finfo`), not just the client-supplied extension, and cap size at
  10 MB (see the file-handling NFRs).
- Generate server-side stored file names; never trust the client file name for the
  storage path.

### 3.6 Web-server headers (Apache)

The app emits security headers from PHP so they apply under the built-in dev server
too; under Apache, `public/.htaccess` mirrors them (requires `mod_headers`). Confirm
`mod_headers` is enabled and `AllowOverride` permits the `.htaccess`.

---

## 4. Notes

- The PHP built-in server (`php -S`) ignores `.htaccess`; that is why security
  headers are also sent from PHP. Apache is the intended production server.
- This document tracks the state of remediation and should be updated when Bucket B
  lands and when the system is first deployed.
