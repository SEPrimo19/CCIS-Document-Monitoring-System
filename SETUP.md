# Running CCIS-DMS on another computer

Follow this start to finish and the system will run on a fresh machine. It takes
about 15 minutes, most of which is the XAMPP download.

Nothing here needs an internet connection except step 1.

---

## 1. Install XAMPP

Download and install **XAMPP with PHP 8.1 or newer** from
<https://www.apachefriends.org/>. The default install location (`C:\xampp` on
Windows) is assumed throughout this guide — if you install somewhere else,
substitute your path wherever you see `C:\xampp`.

XAMPP is the right tool for running this project **on a laptop for development
and for the defense demo**. It is not what you would use to host the system on a
real server — see `docs/SECURITY.md` if the system is ever deployed for actual
use by the college.

**Check your PHP version:**

```
C:\xampp\php\php.exe -v
```

It must report 8.1 or higher. PHP 8.0 and below will not run this project.

**Check the required extensions** are enabled (they are, in a stock XAMPP
install — this is only worth checking if something fails later):

```
C:\xampp\php\php.exe -m
```

The list must include `pdo_mysql`, `fileinfo`, `zip`, and `mbstring`. `zip` and
`fileinfo` are what validate uploaded Word documents; without them every upload
is rejected.

---

## 2. Get the project files

Copy the whole `ccis-dms` folder to the new computer — USB, Google Drive, or
`git clone` if you are using the repository. Put it anywhere you like; it does
**not** have to live inside `C:\xampp\htdocs`.

Two things are deliberately **not** included when you copy from git, and you
must create them yourself in the next steps:

| Missing | Why | Fixed in step |
|---|---|---|
| `.env` | It holds database credentials, so it is git-ignored on purpose | Step 3 |
| The contents of `storage/uploads/` | Uploaded documents are git-ignored | Step 5 (recreated empty) |

> **If you are copying the folder directly (USB / Drive) rather than using git**,
> `.env` and the uploaded files come along with it. Skip nothing, but step 3 will
> already be done — just confirm the file exists.

---

## 3. Create the `.env` file — do not skip this

In the `ccis-dms` folder, copy `.env.example` and name the copy `.env`.

```
copy .env.example .env
```

Open `.env` and make sure this line is present and uncommented:

```
APP_ENV=development
```

**This matters more than it looks.** `APP_ENV` defaults to *production* when it
is unset, and in production mode the database setup script in step 5 will
**refuse to run** (it is destructive, so it protects live data by default). If
step 5 tells you `REFUSED: migrate.php drops and recreates every table`, this
step is what you missed.

The stock database settings in `.env.example` already match a default XAMPP
install (`root`, no password, database `ccis_dms`). Leave them alone unless you
set a MySQL password.

---

## 4. Start MySQL

Open the **XAMPP Control Panel** and press **Start** next to **MySQL**.

You do *not* need to start Apache — step 6 uses PHP's own built-in web server,
which is simpler and avoids configuring a virtual host.

---

## 5. Create and populate the database

Open a terminal **in the `ccis-dms` folder** and run these two commands in order:

```
C:\xampp\php\php.exe scripts/db_setup.php
C:\xampp\php\php.exe scripts/migrate.php
```

The first creates the empty `ccis_dms` database. The second creates all 11
tables and inserts the starting data: the roles, five document types, one
academic period, and five user accounts.

You should see it finish with `DONE. 11 tables: ...`.

> ⚠️ **`migrate.php` is destructive.** It drops and recreates every table, so
> running it again wipes all submissions, notifications, and audit history. Run
> it once during setup. Only run it again if you deliberately want a clean slate.

If the `storage/uploads` folder does not exist, the application creates it on the
first upload — nothing to do.

---

## 6. Run the application

From the same folder:

```
C:\xampp\php\php.exe -S localhost:8000 -t public
```

Leave that terminal window open — closing it stops the server. Then open a
browser to:

**<http://localhost:8000>**

You should see the login page.

---

## 7. Sign in

| Role | Email | Password |
|---|---|---|
| Secretary | `secretary@nwssu.edu.ph` | `Secretary@123` |
| Faculty | `faculty1@nwssu.edu.ph` | `Faculty@123` |
| Faculty | `faculty2@nwssu.edu.ph` | `Faculty@123` |
| Faculty | `faculty3@nwssu.edu.ph` | `Faculty@123` |

These are development accounts with published passwords. They are fine for a
demo on a laptop, and must never be used on a system that is actually deployed.

---

## 8. Walk the system end to end

A fresh database has accounts and document types but **no requirements yet**, so
the faculty checklist starts empty. That is correct, not a bug. To see the full
workflow:

1. Sign in as the **Secretary**.
2. **Periods** — confirm one period is marked *Active* (the setup script seeds
   one). If none is active, press **Make active**.
3. **Requirements → New requirement** — choose a document type, give it a title
   and a deadline, and publish. This immediately creates a *Pending* item for
   every faculty account.
4. Sign in as **Faculty** (`faculty1@…`) — the requirement now appears under
   **My Requirements**, and the notification bell shows it was assigned. Upload
   a PDF or Word file. The status becomes *Submitted*.
5. Back as the **Secretary** — the bell shows a document is waiting, and it is
   in the **Review Queue**. Open it and either approve it or return it with
   comments.
6. Back as **Faculty** — the bell shows the decision. A returned document can be
   re-uploaded, and **Details** shows every version.
7. Back as the **Secretary** — **Monitoring** shows the compliance matrix and
   **Reports** exports to CSV or print.

Use two different browsers (or one normal window plus a private/incognito
window) if you want to stay signed in as the Secretary and a Faculty member at
once. Signing in as a second user in the same browser logs the first one out.

---

## Troubleshooting

**`REFUSED: migrate.php drops and recreates every table, and APP_ENV is 'production'`**
You skipped step 3, or `.env` does not contain `APP_ENV=development`. Fix the
file and re-run. (Do not use `--force` to get around this — the message is
telling you the environment is misconfigured.)

**`DB setup FAILED` / `SQLSTATE[HY000] [2002]`**
MySQL is not running. Start it in the XAMPP Control Panel (step 4).

**`Access denied for user 'root'@'localhost'`**
Your MySQL has a root password. Put it in `.env` as `DB_PASS=yourpassword`.

**Port 8000 is already in use**
Use a different port and open that address instead:
`C:\xampp\php\php.exe -S localhost:8080 -t public`

**Every upload is rejected as "not a valid PDF or Word document"**
The `zip` or `fileinfo` PHP extension is disabled. Check `php -m` (step 1), then
enable the missing one in `C:\xampp\php\php.ini` by removing the `;` in front of
`extension=zip` or `extension=fileinfo`, and restart the server.

**The login page has no styling**
You started the server without `-t public`, so it is serving the wrong folder.
Stop it and re-run the exact command in step 6.

**A page says "No active academic period is set"**
Sign in as Administrator, go to **Periods**, and press **Make active** on one.

---

## Moving the *data* too, not just the code

The steps above give your classmate a working system with fresh starting data.
If you want them to have **your** data — the submissions, decisions, and
uploaded files you have been demonstrating with — you must move two things
**together**, or the database will point at files that do not exist:

**1. The database.** On your machine:

```
C:\xampp\mysql\bin\mysqldump.exe -u root ccis_dms > ccis_dms_backup.sql
```

On theirs, after step 5's `db_setup.php` (but *instead of* `migrate.php`):

```
C:\xampp\mysql\bin\mysql.exe -u root ccis_dms < ccis_dms_backup.sql
```

**2. The uploaded files.** Copy your entire `storage/uploads/` folder over
theirs. These are git-ignored, so they never travel with a `git clone` or a
`git pull` — you have to copy them by hand.

Do both, or neither. A database restored without its files will show submissions
whose downloads fail.
