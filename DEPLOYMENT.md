# Deploying CCIS-DMS

This guide covers putting the system on a **real server** so the College can
actually use it — as opposed to running it on a laptop, which is
[SETUP.md](SETUP.md).

The two are genuinely different jobs. A laptop install optimises for "working in
15 minutes". A deployment holds real faculty compliance records, so it optimises
for not losing them and not leaking them.

> **Read this before you start.** There are six things you *must* do that the
> code cannot do for you — TLS, passwords, the database user, the document root,
> backups, and log rotation. They are listed as a checklist in §1 and explained
> in the rest of the guide. `docs/SECURITY.md` is the reference companion to this
> document; where they overlap, SECURITY.md has the deeper reasoning.

---

## 0. Do not deploy XAMPP

XAMPP is the right tool for development and for your defense demo. It is **not**
a production stack: its defaults are tuned for convenience (MySQL `root` with no
password, phpMyAdmin exposed), it bundles services you do not need and should not
expose (phpMyAdmin, FileZilla FTP, Mercury mail, Tomcat), and security patches
arrive only when Apache Friends re-bundle it.

**Your stack does not change.** PHP + MySQL + Apache is correct. You just install
those components properly on the server instead of using the bundle.

---

## 1. Pre-flight checklist

Nothing below is optional. Tick all six before you let anyone log in.

| # | Item | Why it matters | Where |
|---|---|---|---|
| 1 | DocumentRoot points at `public/` | Otherwise `.env`, `app/`, and every uploaded document are downloadable | §4 |
| 2 | HTTPS, with HTTP redirected to it | Passwords and documents cross the network in clear text without it | §5 |
| 3 | All seeded passwords changed | `Secretary@123` etc. are published in this repository | §6 |
| 4 | Least-privilege database user (not `root`) | Limits the blast radius of any SQL flaw | §7 |
| 5 | Backups of the database **and** `storage/uploads/` | Uploaded documents cannot be reproduced by the office | §8 |
| 6 | `error_log` set and rotated | An unrotated log eventually fills the disk and takes the site down | §9 |

---

## 2. Choosing where to host

### Option A — Shared hosting with cPanel (recommended)

Roughly ₱150–400/month from Hostinger, Namecheap, or a local Philippine host.
You get Apache, PHP, MySQL, and free Let's Encrypt TLS already configured. It
matches this stack exactly and needs no server administration.

**Check one thing before you buy:** you must be able to point a domain or
subdomain's document root at a subfolder (`public/`). Most hosts allow this for
addon domains and subdomains. If a host will not, see the workaround in §4.

### Option B — VPS

About $5/month (DigitalOcean, Hetzner, Vultr). Full control, and you own the
patching. Install from the distribution's own repositories:

```bash
sudo apt update
sudo apt install apache2 php php-mysql php-mbstring php-zip php-xml mariadb-server certbot python3-certbot-apache
sudo a2enmod rewrite headers
```

### Option C — A campus server

If CCIS or the MIS office will host it, the same guidance applies. **Consider
whether it needs to be on the public internet at all** — only college staff use
this system, so a LAN-only deployment is a legitimate choice that removes most of
the exposure. You still want HTTPS internally (an internal CA is fine).

---

## 3. Get the code and dependencies onto the server

There are no Composer packages, no npm, and no build step — it is PHP source and
static assets. Either:

```bash
git clone https://github.com/SEPrimo19/CCIS-Document-Monitoring-System.git
```

…or upload the folder over SFTP / cPanel File Manager.

**Requirements:** PHP **8.1+** with `pdo_mysql`, `fileinfo`, `zip`, and
`mbstring` enabled, and MySQL 5.7+ / MariaDB 10.3+. Verify with `php -v` and
`php -m`. Without `zip` and `fileinfo` every Word upload is rejected.

Make sure `storage/uploads/` exists and is writable by the web-server user:

```bash
mkdir -p storage/uploads
sudo chown -R www-data:www-data storage/uploads
sudo chmod 775 storage/uploads
```

---

## 4. Point the document root at `public/` — the highest-risk step

This is the single most damaging mistake available. If the document root is the
**project folder** instead of `public/`, Apache will happily serve `.env` (your
database password), `database/schema.sql`, the whole `app/` source tree, and
every file in `storage/uploads/`.

**Apache virtual host:**

```apache
<VirtualHost *:443>
    ServerName dms.nwssu.edu.ph
    DocumentRoot /var/www/ccis-dms/public

    <Directory /var/www/ccis-dms/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  /var/log/apache2/ccis-dms-error.log
    CustomLog /var/log/apache2/ccis-dms-access.log combined
</VirtualHost>
```

`AllowOverride All` matters — it is what lets `public/.htaccess` do the URL
rewriting and send the security headers.

**cPanel:** create a subdomain and set its Document Root to
`.../ccis-dms/public`.

**If your host truly cannot point at a subfolder:** put the *contents* of
`public/` into `public_html/`, move everything else one level **above**
`public_html/`, and edit the two `require` paths at the top of
`public_html/index.php` to match. This is a last resort — it is easy to get
wrong.

A deny-all `.htaccess` sits at the repository root as a safety net. Apache only
reads `.htaccess` from the document root downward, so it is inert in a correct
deployment and only fires in the mis-set case, failing the site closed.
**If every page returns 403, your document root is wrong** — that is the safety
net doing its job, not a broken application.

### Verify before going live

```bash
curl -I https://your-domain/.env                    # must be 403 or 404
curl -I https://your-domain/app/Core/Database.php   # must be 403 or 404
curl -I https://your-domain/storage/uploads/        # must be 403 or 404
curl -I https://your-domain/                        # must be 200
```

If any of the first three returns 200, **stop and fix the document root.**

---

## 5. HTTPS

The application sets the session cookie's `Secure` flag based on the actual
transport, but **it cannot force HTTPS by itself** — that is a web-server
setting.

```bash
sudo certbot --apache -d dms.nwssu.edu.ph
```

Certbot offers to add the HTTP→HTTPS redirect; accept it. Then add HSTS to the
`:443` virtual host:

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

On cPanel, enable *AutoSSL* and *Force HTTPS Redirect*.

---

## 6. Create the database and the real accounts

```bash
mysql -u root -p -e "CREATE DATABASE ccis_dms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p ccis_dms < database/schema.sql
mysql -u root -p ccis_dms < database/seed.sql
```

`seed.sql` inserts the two roles, the document types, and a sample academic
period. It does **not** create users — passwords have to be bcrypt-hashed by
PHP, which is what `scripts/migrate.php` normally does.

> ⚠️ **Do not run `scripts/migrate.php` on a live server.** It **drops and
> recreates every table**. It refuses to run unless `APP_ENV=development`
> precisely to stop this, and you should not use `--force` to get around it.

Create the first Secretary account with a strong password of your own:

```bash
php -r 'echo password_hash("PUT-A-STRONG-PASSWORD-HERE", PASSWORD_BCRYPT), PHP_EOL;'
```

```sql
INSERT INTO users (role_id, employee_no, first_name, last_name, email, password_hash, program_id, status)
VALUES ((SELECT role_id FROM roles WHERE role_name='Secretary'),
        'SEC-001', 'College', 'Secretary', 'secretary@nwssu.edu.ph',
        '<paste the hash here>', NULL, 'active');
```

`program_id` is NULL because the Secretary belongs to the office, not to an
academic program. Faculty accounts get one of the seeded `programs` rows, which
is what program-targeted requirements key off (FR-35, FR-36).

Then sign in as that account and create every other user through
**Users → New user**. There is no public self-registration by design.

**Never deploy with `Secretary@123` or `Faculty@123`.** They are published in
this repository. There is no forced-password-change screen yet, so nothing in
the software will stop you — this step is on you.

---

## 7. Configuration (`.env`)

Create `.env` in the project root (**not** in `public/`). It is git-ignored, so
it never arrives with a clone.

```
APP_ENV=production
APP_NAME="CCIS Document Monitoring System"
APP_TIMEZONE=Asia/Manila

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ccis_dms
DB_USER=ccis_dms_app
DB_PASS=a-long-random-password
```

`APP_ENV=production` hides exception detail from users. Unset also means
production — the safe direction — but set it explicitly so intent is obvious.

Create the least-privilege database user it refers to:

```sql
CREATE USER 'ccis_dms_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ccis_dms.* TO 'ccis_dms_app'@'localhost';
FLUSH PRIVILEGES;
```

No DDL, no `GRANT`, no `FILE`: the application never creates tables, so it does
not need to. Schema changes are an operator job with a privileged account.

Lock the file down:

```bash
chmod 640 .env
sudo chown root:www-data .env
```

Recommended `php.ini` for production:

```ini
display_errors = Off
log_errors = On
expose_php = Off
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
upload_max_filesize = 10M
post_max_size = 12M
```

`post_max_size` must exceed `upload_max_filesize`, or a 10 MB upload fails before
PHP can produce a friendly message.

---

## 8. Backups — do this before go-live, not after

Once this system replaces the paper process it becomes the only record of
faculty compliance, and an uploaded document generally cannot be reproduced by
the office.

Two things must be backed up **together**. Restore a database without its files
and you get rows pointing at documents that no longer exist.

```bash
#!/bin/bash
# /usr/local/bin/ccis-dms-backup.sh
set -euo pipefail
STAMP=$(date +%F)
DEST=/var/backups/ccis-dms
mkdir -p "$DEST"

mysqldump -u backup_user -p'...' ccis_dms | gzip > "$DEST/db-$STAMP.sql.gz"
tar czf "$DEST/uploads-$STAMP.tar.gz" -C /var/www/ccis-dms storage/uploads

find "$DEST" -name '*.gz' -mtime +90 -delete
```

```
0 2 * * *  /usr/local/bin/ccis-dms-backup.sh
```

- Keep copies **off the application server**.
- **Test a restore at least once.** An untested backup is not a backup.
- Retain at least one full academic period, so a problem noticed at the end of a
  semester is still recoverable.

On cPanel, use the built-in backup scheduler — just confirm it includes both the
database *and* the `storage/uploads` directory.

---

## 8b. Optional: in-app viewing of Word submissions

The viewer shows a PDF submission as itself on any browser, with no server-side
help. A **Word** submission has no native renderer in any browser, so the server
converts it to PDF first — and that conversion happens **on the server**, not on
the reader's machine.

The practical consequence: **install LibreOffice on the server and every user
gets the paginated PDF view**, on any device, with nothing installed at their
end. Skip it and everyone falls back to the rendered-contents view instead —
readable, in-app, but without pagination or exact page layout. No user is
required to install anything either way.

On Debian/Ubuntu, the Writer component alone is enough and much smaller than the
full suite:

```
sudo apt-get install --no-install-recommends libreoffice-writer
```

The application finds `/usr/bin/soffice` on its own. Set `SOFFICE_PATH` in the
environment only for an install somewhere unusual.

Two things the web server user needs, and both fail quietly if missed:

- **Write access to `storage/previews/`**, where converted PDFs are cached. Same
  ownership as `storage/uploads/`.
- **`proc_open` not disabled** in `php.ini`. Shared hosting disables it more
  often than not. The application checks for this and falls back rather than
  promising a preview it cannot produce, so the symptom is the fallback view
  rather than an empty frame — but the conversion will simply never run.

Verify after deploying: sign in, open a Word submission, and confirm it shows as
a PDF with page controls. If it shows the rendered contents instead, check those
two conditions before anything else.

Conversions are cached under `storage/previews/` keyed by the source file's size
and modification time, so each document converts once and a re-uploaded version
gets its own entry. The directory is safe to clear at any time; the next view
reconverts. Include it in backups only if you would rather not pay for the
reconversion — nothing there is unrecoverable.


## 9. Logging

`error_log` is the application's only log sink; it is where best-effort failures
(audit writes, notification creation) are recorded. Point it at a file outside
the web root and rotate it:

```
/var/log/ccis-dms/*.log {
    weekly
    rotate 12
    compress
    missingok
    notifempty
    create 640 www-data adm
}
```

Logs may contain email addresses — treat the directory as sensitive.

---

## 10. Post-deployment verification

Work through this before handing the system over:

- [ ] `https://your-domain/` shows the login page over HTTPS
- [ ] Plain `http://` redirects to `https://`
- [ ] `/.env`, `/app/Core/Database.php`, `/storage/uploads/` all fail (§4)
- [ ] Signing in with `Secretary@123` **fails** — you changed it
- [ ] Secretary can publish a requirement; Faculty sees it and gets a notification
- [ ] Faculty can upload a PDF; the Secretary is notified and the queue shows it
- [ ] Approve and return both work, and the audit log records them
- [ ] Download works, and a faculty member cannot download another's file
- [ ] **Open a document with "View" — the page renders inside the app, not as an
      empty box.** This is the one feature Apache can break on its own: see the
      note in `public/.htaccess` about header directives and the framed route.
- [ ] Reports export to CSV and print
- [ ] A wrong password five times triggers the lockout message
- [ ] The backup script has run once and you have **restored** it somewhere

---

## 11. Ongoing operations

**Each new semester:** Periods → New academic period → **Make active**. The
previous period closes automatically and moves to the Archive; nothing is
deleted.

**Updating the code:**

```bash
cd /var/www/ccis-dms
sudo -u www-data git pull
```

`.env` and `storage/uploads/` are git-ignored, so a pull never touches your
configuration or your documents. If a release ever changes the schema it will
ship a migration note — apply it with a privileged account, never with
`migrate.php`.

---

## Known limitations to plan around

Honest disclosure, so none of these surprises you in production:

- **No forced password change on first login.** Set strong passwords yourself
  when creating accounts (§6).
- **A password change does not sign out the user's other sessions.** On shared
  office computers, sign out explicitly.
- **Notifications are in-app only** — no email or SMS. Users see them on their
  next visit.
- **Deadline reminders are generated when a faculty member loads their
  dashboard**, not by a scheduler. Someone who never logs in gets the reminder
  when they next do. This is deliberate: it needs no cron and works on shared
  hosting.
- **Closing the active period empties every checklist, the queue, and the
  monitoring board** until another is activated. It is reversible and
  audit-logged, but treat it as a scheduled end-of-semester action.

`docs/SECURITY.md` §1.1 tracks these with proposed fixes.
