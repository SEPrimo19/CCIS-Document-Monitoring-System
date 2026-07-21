# CCIS-DMS — CCIS Document Monitoring System

Capstone project · Northwest Samar State University · College of Computing and Information Sciences.

**Stack:** PHP (back-end) · MySQL (database) · HTML5 / CSS3 / JavaScript (front-end) · web-based.
**Methodology:** RAD / Prototyping. See `../ROADMAP.md` for the full development plan.

This is the **Phase 0 scaffold** — a minimal plain-PHP MVC skeleton proving the
Apache → PHP → MySQL stack works end to end. Features are built from Phase 3 onward.

## Structure
```
ccis-dms/
├─ public/            # web root (point Apache/your server here)
│  ├─ index.php       # front controller
│  ├─ .htaccess       # routes all requests to index.php
│  ├─ assets/         # css, js
│  └─ uploads/        # uploaded documents (git-ignored)
├─ app/
│  ├─ Core/           # Router, Controller, Database (PDO)
│  ├─ Controllers/    # request handlers
│  └─ Views/          # HTML templates
├─ config/            # config.php (reads .env, falls back to XAMPP defaults)
├─ database/          # schema.sql (filled from the Phase 2 ERD)
└─ scripts/           # db_setup.php, smoke.php
```

## Setup (local, XAMPP)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. (Optional) copy `.env.example` to `.env` and adjust credentials. Defaults match XAMPP
   (`root` / no password / database `ccis_dms`).
3. Create the database, then create the tables and seed data (roles, document types,
   a sample academic period, and a default admin):
   ```
   php scripts/db_setup.php
   php scripts/migrate.php
   ```
   Default admin: `admin@nwssu.edu.ph` / `Admin@123` (change it after first login).
   The password comes from the `ADMIN_PASSWORD` environment variable and defaults to
   `Admin@123` for local dev when unset — set `ADMIN_PASSWORD` before running `migrate.php`
   to seed a different admin password instead.

   `migrate.php` also seeds dev-only Reviewer/Approver and Faculty accounts for
   exercising the review and submission flows (documented dev credentials — same
   caveat as the admin default, change before any real deployment):
   - Reviewer/Approver: `reviewer@nwssu.edu.ph` / `Reviewer@123` (from `REVIEWER_PASSWORD`)
   - Faculty: `faculty1@nwssu.edu.ph`, `faculty2@nwssu.edu.ph`, `faculty3@nwssu.edu.ph`,
     all `Faculty@123` (from `FACULTY_PASSWORD`)

   Note: `migrate.php` is destructive in development — it drops and recreates all tables.
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
