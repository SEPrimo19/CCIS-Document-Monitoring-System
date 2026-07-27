# CCIS-DMS — CCIS Document Monitoring System

Capstone project · Northwest Samar State University · College of Computing and Information Sciences.

**Stack:** PHP (back-end) · MySQL (database) · HTML5 / CSS3 / JavaScript (front-end) · web-based.
**Methodology:** RAD / Prototyping. See `../ROADMAP.md` for the full development plan.

A plain-PHP MVC application: a front controller, a small router, PDO models, and
server-rendered views. No framework and no build step — PHP, MySQL, and static
CSS/JS only.

## Structure
```
ccis-dms/
├─ public/            # web root (point Apache/your server here — and ONLY here)
│  ├─ index.php       # front controller
│  ├─ .htaccess       # routes all requests to index.php + security headers
│  └─ assets/         # css, js
├─ app/
│  ├─ Core/           # Router, Controller, Database (PDO), Auth, Csrf, Guard
│  ├─ Controllers/    # request handlers
│  ├─ Models/         # table access (PDO prepared statements)
│  └─ Views/          # HTML templates
├─ config/            # env.php (.env loader) + config.php
├─ database/          # schema.sql, seed.sql
├─ docs/              # SECURITY.md — deployment hardening checklist
├─ storage/
│  └─ uploads/        # uploaded documents — OUTSIDE the web root, git-ignored;
│                     # served only through the authenticated download route
├─ scripts/           # db_setup.php, migrate.php, smoke.php
└─ .htaccess          # deny-all safety net if DocumentRoot is mis-set
```

> **Setting this up on another computer?** Follow **[SETUP.md](SETUP.md)** instead —
> it is a step-by-step guide written for a fresh machine, including the two files
> that a `git clone` deliberately does not bring with it.

## Setup (local, XAMPP)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy `.env.example` to `.env` for local development, and adjust credentials if needed.
   Defaults match XAMPP (`root` / no password / database `ccis_dms`). Without a `.env`
   file (or with `APP_ENV` unset/anything other than `development`), the app now
   defaults to the hardened production posture — see `docs/SECURITY.md`.
3. Create the database, then create the tables and seed data (roles, document types,
   a sample academic period, and a default admin):
   ```
   php scripts/db_setup.php
   php scripts/migrate.php
   ```
   Default Secretary: `secretary@nwssu.edu.ph` / `Secretary@123` (change it after first
   login). The password comes from the `SECRETARY_PASSWORD` environment variable and
   defaults to `Secretary@123` for local dev when unset — set `SECRETARY_PASSWORD`
   before running `migrate.php` to seed a different password instead.

   `migrate.php` also seeds dev-only Faculty accounts for exercising the submission
   flow (documented dev credentials — same caveat as the Secretary default, change
   before any real deployment):
   - Faculty: `faculty1@nwssu.edu.ph`, `faculty2@nwssu.edu.ph`, `faculty3@nwssu.edu.ph`,
     all `Faculty@123` (from `FACULTY_PASSWORD`)

   Note: `migrate.php` is **destructive** — it drops and recreates every table, wiping all
   submissions, notifications, and audit-log entries. It refuses to run unless
   `APP_ENV=development`; on any other environment (including an unset `APP_ENV`, which
   defaults to production) it exits with an error unless you pass `--force`.
4. Run it — simplest is PHP's built-in server:
   ```
   php -S localhost:8000 -t public
   ```
   then open http://localhost:8000 . You should see the status page with **MySQL: Connected**.

   *(Alternatively, add an Apache virtual host whose DocumentRoot is this `public/` folder.)*

## Verify without a browser
```
php scripts/smoke.php
```
Prints the rendered status page (app + PHP + MySQL check) to the terminal.

## Deploying
Do not deploy straight from these instructions — local defaults are deliberately
convenient, not safe. Work through **`docs/SECURITY.md`** first; at minimum it covers
setting `DocumentRoot` to `public/`, serving over HTTPS, replacing the seeded passwords,
creating a least-privilege database user instead of `root`, and backing up both the
database and `storage/uploads/`.
