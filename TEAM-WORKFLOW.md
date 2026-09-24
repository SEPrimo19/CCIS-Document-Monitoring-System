# Working on this together

Commands for running the system day to day and for keeping two machines in
step. `SETUP.md` covers a first install in full; this is the short reference for
people who already have it working.

**Everything below runs from inside `ccis-dms`.** That is the project root, the
folder holding `public/` and `app/`. If a command reports that a script cannot
be found, you are almost certainly one level too high: `cd ccis-dms` first.

---

## Run it

Start **MySQL** in the XAMPP Control Panel, then:

```
C:\xampp\php\php.exe -S localhost:8000 -t public
```

Open <http://localhost:8000>. Stop the server with `Ctrl+C`.

Seed logins are `secretary@nwssu.edu.ph` / `Secretary@123` and
`faculty1@nwssu.edu.ph` / `Faculty@123`.

---

## First install on a new machine

```
git clone https://github.com/SEPrimo19/CCIS-Document-Monitoring-System.git
cd CCIS-Document-Monitoring-System
copy .env.example .env
echo APP_ENV=development>> .env
C:\xampp\php\php.exe scripts\db_setup.php
C:\xampp\php\php.exe scripts\migrate.php
C:\xampp\php\php.exe -S localhost:8000 -t public
```

The `echo APP_ENV=development>> .env` line is **not optional**. `.env.example`
ships with `APP_ENV` commented out, so that copying it can never put a real
deployment into debug mode. Without the line, `migrate.php` stops with:

```
REFUSED: migrate.php drops and recreates every table, and APP_ENV is 'production'.
```

**Clone, do not copy the folder.** A folder copy carries the other person's
`.env`, their `.git`, and roughly 187 MB of old upload files, and it cannot
receive updates. A clone gets none of that and updates with one command.

---

## Getting someone else's changes

```
git pull
C:\xampp\php\php.exe scripts\doctor.php
```

### `git pull` updates the code. It does not update your database.

This is the single most common way to end up with a broken copy, and it has
already happened once on this project. The code learns about a new column, your
database does not have it, and every signed-in page answers 500 with something
like:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'u.avatar_path' in 'field list'
```

`doctor.php` compares your database against `database/schema.sql` and names any
column that is missing. If it reports drift, bring the database up to date
**without losing your data**:

```
C:\xampp\php\php.exe scripts\migrate_batch_a.php
C:\xampp\php\php.exe scripts\migrate_batch_c.php
```

Both are `ALTER`-based and safe to run more than once.

**Do not use `migrate.php` for this.** It drops and recreates all 13 tables.
It is the right tool for a first install and the wrong one for an update.

### If `git pull` refuses because you have local edits

```
git stash
git pull
git stash pop
```

---

## Pushing your own changes

```
git add path/to/the/file/you/changed
git commit -m "what changed and why"
git push
```

Name the files explicitly rather than using `git add -A`. On this project a
blanket add once swept unrelated edits from someone's editor into a commit, and
they had to be restored by hand afterwards.

Only collaborators can push. The repository is public, so anyone can clone and
read it, but pushing requires being added under **Settings, Collaborators** on
GitHub.

If you both edit the same file, `git pull` will report a merge conflict. Open
the file, keep the version you want, remove the `<<<<<<<` markers, then commit.
Pulling before you start work makes this rare.

---

## When something is broken

```
C:\xampp\php\php.exe scripts\doctor.php
```

Run this first, always. It is read-only and changes nothing. It checks, in
order: that you are in the right folder, the PHP version, the four required
extensions, that `.env` exists, what `APP_ENV` resolves to, that MySQL is
reachable, that the database and its 13 tables exist, that your database matches
`schema.sql`, that the storage folders accept a write, and whether LibreOffice is
installed. Each failure prints the command that fixes it.

If it reports everything is fine and the app still misbehaves, say what you did
and what you saw, and include the exact error text. "It errors" cannot be acted
on; `SQLSTATE[42S22] ... Unknown column 'u.avatar_path'` can be fixed in one
command.

---

## Checking you have not broken anything

The app must be running for these. They are safe against real data, and each one
prints a pass and fail count at the end.

```
bash docs/qa/suites/smoke_postcommit.sh
bash docs/qa/suites/suite_rbac.sh
bash docs/qa/suites/suite_navigation.sh
bash docs/qa/suites/suite_document_viewer.sh
bash docs/qa/suites/suite_security_audit.sh
```

The other suites in that folder write to the database and do not put it back.
Run those only against a database you are willing to rebuild. `docs/qa/README.md`
says which is which and why.

To run a suite against a different server, such as Apache rather than the PHP
development server:

```
CCIS_BASE=http://localhost/ccis bash docs/qa/suites/suite_security_audit.sh
```

The two servers genuinely behave differently. That is how a configuration fault
was found that would have disabled the in-app document viewer once deployed,
while every test still passed in development.

---

## Things that bite

- **Two copies on one machine.** If you were sent a folder copy and later cloned
  the repository, delete the copy. Otherwise you will edit one and run the other
  and lose an afternoon to it.
- **The project root is `ccis-dms/`.** Being handed the parent folder is the
  usual cause of `scripts/db_setup.php` not found.
- **`.env` is deliberately not in the repository.** It holds settings that differ
  per machine, so every machine makes its own from `.env.example`.
- **Uploaded documents are not in the repository either.** They live in
  `storage/uploads/`, which is ignored by git. A clone starts with an empty one,
  which is correct.
- **The seed passwords are published here.** Fine for coursework. Before the
  system is used for real, change them. `DEPLOYMENT.md` lists that as a
  pre-flight item along with HTTPS and a least-privilege database user.
