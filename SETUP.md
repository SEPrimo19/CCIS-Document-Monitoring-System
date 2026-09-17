# Running CCIS-DMS on another computer

Follow this start to finish and the system will run on a fresh machine. It takes
about 15 minutes, most of which is downloading XAMPP.

You need an internet connection for steps 1 and 2 only. Everything after that
runs entirely offline on your own machine.

## The short version

If you already have XAMPP with PHP 8.1+ installed, this is the whole thing:

```
git clone https://github.com/SEPrimo19/CCIS-Document-Monitoring-System.git
cd CCIS-Document-Monitoring-System
copy .env.example .env
```

Start **MySQL** in the XAMPP Control Panel, then:

```
C:\xampp\php\php.exe scripts/db_setup.php
C:\xampp\php\php.exe scripts/migrate.php
C:\xampp\php\php.exe -S localhost:8000 -t public
```

Open <http://localhost:8000> and sign in as `secretary@nwssu.edu.ph` /
`Secretary@123`.

If any of that fails, or you are starting from nothing, follow the full steps
below — they explain what each command does and how to fix the common problems.

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

## 2. Download the source code

The project lives at:

**<https://github.com/SEPrimo19/CCIS-Document-Monitoring-System>**

Put it anywhere you like — Desktop, Documents, wherever. It does **not** have to
live inside `C:\xampp\htdocs`. Avoid folder names with unusual characters.

### Option A — Git (recommended)

If you have Git installed (`git --version` to check; otherwise get it from
<https://git-scm.com/download/win>):

```
git clone https://github.com/SEPrimo19/CCIS-Document-Monitoring-System.git
cd CCIS-Document-Monitoring-System
```

The advantage is that `git pull` later fetches any updates without redoing the
setup.

### Option B — Download ZIP (no Git needed)

1. Open the repository link above in a browser.
2. Click the green **Code** button → **Download ZIP**.
3. Right-click the downloaded file → **Extract All…**
4. Open the extracted folder. If it is named
   `CCIS-Document-Monitoring-System-master`, that is normal.

> **Extract it before using it.** Windows lets you browse *inside* a `.zip`
> without unpacking, and the commands below will fail confusingly if you try to
> run them from there.

### Option C — USB or Google Drive

If someone hands you the folder directly, just copy it across. In this case
`.env` and any uploaded documents come with it, so step 3 may already be done —
just confirm the `.env` file exists.

### What is deliberately missing from a Git/ZIP download

| Missing | Why | Fixed in |
|---|---|---|
| `.env` | It holds database credentials, so it is git-ignored on purpose | Step 3 |
| Uploaded documents in `storage/uploads/` | Faculty documents are git-ignored — you start with an empty folder | Nothing to do; the app creates files as you upload |

This is expected, not a broken download.

---

## 3. Create the `.env` file — do not skip this

In the project folder, copy `.env.example` and name the copy `.env`.

```
copy .env.example .env
```

(On PowerShell you can also use `Copy-Item .env.example .env`. In File Explorer:
copy-paste the file and rename the copy to exactly `.env` — no `.txt` on the end.
Turn on **View → File name extensions** so you can see what it is really called.)

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

Open a terminal **in the project folder** and run these two commands in order.
(Quickest way to get a terminal in the right place: open the folder in File
Explorer, then type `cmd` in the address bar and press Enter.)

```
C:\xampp\php\php.exe scripts/db_setup.php
C:\xampp\php\php.exe scripts/migrate.php
```

The first creates the empty `ccis_dms` database. The second creates all 13
tables and inserts the starting data: the two roles, four academic programs,
five document types, one academic period, and four user accounts (one Secretary
and three Faculty).

You should see it finish with `DONE. 13 tables: ...`.

> ⚠️ **`migrate.php` is destructive.** It drops and recreates every table, so
> running it again wipes all submissions, notifications, and audit history. Run
> it once during setup. Only run it again if you deliberately want a clean slate.

### Already have a database from before 2026-09-16?

If you set this up earlier and have real submissions in it, do **not** run
`migrate.php` again — it would wipe them. Run the incremental migration instead:

```
C:\xampp\php\php.exe scripts/migrate_batch_a.php
```

It adds the `programs` and `requirement_targets` tables, moves each user's
program from the old free-text `program_dept` column to the new `program_id`
foreign key, and renames the submission status `Returned-for-revision` to
`Revised` — all with `ALTER`/`UPDATE`, touching no existing row it does not have
to. Every step checks whether it has already been applied, so running it twice
is harmless. A fresh `migrate.php` install already includes all of it and does
not need this script.

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

### Starting it again next time

Once setup is done you never repeat steps 1–5. To run the system on any later
day, just start **MySQL** in the XAMPP Control Panel and run:

```
C:\xampp\php\php.exe -S localhost:8000 -t public
```

Do **not** run `migrate.php` again unless you want to erase everything and start
from a clean slate.

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
Sign in as the Secretary, go to **Periods**, and press **Make active** on one.

**`'git' is not recognized` / `'php' is not recognized`**
Git is not installed (use Option B in step 2 instead), or you are typing `php`
rather than the full path. This project always spells out
`C:\xampp\php\php.exe` so it works without adding anything to your PATH.

**`Could not open input file: scripts/db_setup.php`**
Your terminal is not in the project folder. `cd` into the folder that contains
`README.md` and `SETUP.md`, then run the command again.

**The site loads but every page is blank**
Check the terminal running the server — PHP prints the error there. The most
common cause is MySQL having stopped since you started the server.

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

## Optional: Word documents shown as PDF

The in-app viewer (FR-41) shows a PDF submission as itself. A Word submission
has no native renderer in any browser, so the system falls back to rendering the
document's contents — text, emphasis, tables and images — which is readable but
has no pagination and not the exact page layout.

Install **LibreOffice** and Word submissions are instead converted to PDF and
shown in the browser's own PDF viewer, with pages, zoom and the real layout:

1. Download it from <https://www.libreoffice.org/download/> and install with the
   defaults.
2. Restart the PHP server so the new install is picked up.

That is the whole setup. No configuration and no code change: the application
looks for LibreOffice at the usual Windows, Linux and macOS paths, and switches
on the moment it finds one. Set `SOFFICE_PATH` in the environment only if it is
installed somewhere unusual.

**It is genuinely optional.** Without it nothing breaks — the viewer simply uses
the rendered-contents fallback, and every other feature is unaffected.

Conversions are cached under `storage/previews/`, keyed by the source file's
size and modification time, so each document is converted once and a re-uploaded
version gets a new entry rather than a stale one. The cache is outside the web
root and is not committed; deleting it only means the next view reconverts.

LibreOffice was chosen over driving Microsoft Word through PHP's COM extension
because it runs headless on Windows *and* Linux. Word automation would have tied
the system to a Windows server with Office licensed on it, which would not
survive deployment to a campus server.

