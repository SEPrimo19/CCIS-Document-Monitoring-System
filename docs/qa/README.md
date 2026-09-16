# QA evidence — CCIS-DMS

This folder is the **durable home** of the Phase 5 functional QA audit. The suites
and their results previously lived only in a session scratchpad and were lost
twice; `common.sh` now resolves its own directory, so everything here runs from
wherever the repo is checked out.

## What is here

| Path | What it is |
|---|---|
| `RESULTS-2026-09-14.md` | The 453-line evidence file for the audit of 2026-09-14: **391 checks, 0 failures**. Per-suite results, method notes, and the reasoning behind every non-obvious expectation. |
| `suites/suite_*.sh` | The 13 functional suites, covering FR-1..FR-34. |
| `suites/setup_fixtures.sh` | Creates the requirement/submission fixtures through the real Secretary endpoints (not raw SQL), so notification and audit side effects are exercised. Contributes 5 checks. |
| `suites/common.sh` | Shared helpers: login, CSRF scraping, assertions. |
| `suites/suite_document_viewer.sh` | FR-41 in-app viewer (20 checks): access control matching `/download` including 404-not-403, that the viewer either previews or says why it cannot, that extracted .docx text is labelled as a text extract, that only the inline bytes route relaxes `X-Frame-Options` while ordinary pages stay `DENY`, and that an extension-bearing URL does not bypass the router. Read-only — safe against real data. |
| `suites/suite_profile_photo.sh` | FR-40 profile photos (26 checks): the accepted formats, every rejection path (SVG, PHP renamed to .png, text renamed to .jpg, oversized by bytes and by pixels, too small, type/extension mismatch, zero bytes), that replacing and removing a photo unlink the old file, and the access rules. Safe against real data — it only touches faculty1's own avatar and removes it again. Run `make_avatar_fixtures.sh` first. |
| `suites/smoke_postcommit.sh` | Fast 49-check smoke test (added 2026-09-15) — environment, auth, RBAC, the period confirmation guard, every screen route, and CSV export. Non-destructive: GETs and logins only. |

## Counting note

391 = the 13 suites **plus** the 5 checks in `setup_fixtures.sh`. Say
"391 checks across 13 suites" — not "13 scripts", since the fixture script is
the 14th file and is not a suite.

## Which suites are safe to run

**Only three suites are safe against a database that holds real data:**

| Safe (read-only) | Destructive (writes, and does NOT restore) |
|---|---|
| `suite_navigation.sh` | `suite_profile.sh` |
| `suite_rbac.sh` | `suite_review.sh` |
| `suite_profile_photo.sh` | |
| `smoke_postcommit.sh` | `suite_resubmit.sh` |
| | `suite_admin_crud.sh` |
| | `setup_fixtures.sh` |

The destructive ones assert their own mutations, so they report a clean pass
while leaving the database changed. **`suite_profile.sh` is the worst of them:
it renames faculty3 and changes that account's email and password, so the
seeded `faculty3@nwssu.edu.ph` / `Faculty@123` login stops working** — and it
still reports 18/18 passed.

They were written to run against a **clean reseed** (`scripts/db_setup.php`
then `scripts/migrate.php`), where their fixture assumptions hold and there is
nothing of value to lose. Run them there, not against demo data you care about.
If you must run them against live data, take a `mysqldump` first.

**And read this before restoring one.** A default `mysqldump` of this database
begins with `CREATE DATABASE ... ccis_dms` and `USE ccis_dms`, so piping it into
*any* database name loads it into **ccis_dms** regardless. On 2026-09-17 a dump
was loaded into a scratch database to recover a single row and instead reverted
the live schema to its pre-migration state — `users.program_id` and
`avatar_path` dropped, the status enum back to `Returned-for-revision`.
Recovery was only cheap because `migrate_batch_a.php` and `migrate_batch_c.php`
are idempotent and simply re-applied. Either strip those two lines first, or
dump with `--no-create-db` and restore with an explicit database argument, and
check what the file actually contains before running it:

```
grep -iE "^USE |^CREATE DATABASE" dump.sql
```

This is not hypothetical: on 2026-09-16 a full-suite run against the live demo
data broke the faculty3 seed login and altered submissions, and had to be
restored by hand from a measurement taken beforehand.

## Re-running

MySQL up, then from the repo root:

```
C:\xampp\php\php.exe -S localhost:8000 -t public
```

Then, from this folder:

```
bash suites/suite_rbac.sh          # read-only, safe any time
bash suites/smoke_postcommit.sh    # read-only, safe any time
```

The faculty-flow, review and resubmit suites hard-code submission ids 1-6 and
expect the fixtures from `setup_fixtures.sh` against a **clean reseed**
(`scripts/db_setup.php` then `scripts/migrate.php`). `migrate.php` drops and
recreates all 13 tables — it refuses to run unless `.env` has
`APP_ENV=development`, and that refusal should never be worked around.

The six upload-validation fixtures are **generated, not committed** — run
`bash suites/make_fixtures.sh` once before the faculty-flow suite. Two of them
do not belong in a repository: `malicious.exe` carries an MZ (DOS executable)
header and makes antivirus flag a fresh clone, and `oversized.pdf` is 11 MB of
padding. They are listed in `suites/.gitignore`.

Verified MIME types, which is what the validator actually keys on:
`valid.pdf`/`valid_v2.pdf` → `application/pdf` (accepted); `fake_renamed.pdf`
→ `text/plain` despite its `.pdf` name (rejected by the structural check, not
the extension allowlist); `zero.pdf` → `application/x-empty`;
`malicious.exe` → `application/x-dosexec`; `oversized.pdf` → a real PDF that
is simply over the 10 MB cap.

## Naming change since the audit: `Returned-for-revision` is now `Revised`

On **2026-09-16** the fourth submission status was renamed at the client's
request:

| Before | After |
|---|---|
| `submissions.status = 'Returned-for-revision'` | `'Revised'` |
| `reviews.decision = 'Returned-for-revision'` | `'Revised'` |
| status-pill class `status-pill-returned` | `status-pill-revised` |
| dashboard bar segment `seg-returned` | `seg-revised` |
| CSV column header `Returned` | `Revised` |
| stat-card heading `<h2>Returned</h2>` | `<h2>Revised</h2>` |

The suites here have been updated: `suite_review.sh`, `suite_resubmit.sh`,
`suite_dashboard_figures.sh` and `suite_monitoring_reports.sh` all post and
assert the new name. Apply the same rename to any suite written against the old
one.

**`RESULTS-2026-09-14.md` is deliberately NOT updated.** It is the dated
evidence record of a run that really happened, under the old name, against the
code as it stood on that date. Rewriting it would falsify the record. Read every
`Returned-for-revision` in it as today's `Revised`.

Note that the *action* wording is unchanged — the Secretary still "returns a
document for revision", and the notification still reads "Document returned for
revision". Only the resulting **status** is renamed.

The same date added FR-35 (requirement audience targeting) and FR-36
(Secretary-assigned academic programs). Two consequences for these suites:

- `/profile` **no longer has a program field**. `suite_profile.sh` used to post
  `program_dept=BSCS` on every profile update; those lines are gone, because the
  column is gone. Program is now assigned by the Secretary at
  `/admin/users/{id}/edit` (field name `program_id`).
- Publishing a requirement now posts an `applies_to` audience. Omitting it
  defaults to `all_faculty`, so existing fixture scripts keep working unchanged.

## Scope limits of the 2026-09-14 audit

- It was **functional** QA against FR-1..FR-34. It did not cover the two
  known-open defects in `CLAUDE.md`, and **no security audit was run**.
- It predates **FR-35/FR-36** (requirement audience targeting and
  Secretary-assigned programs, 2026-09-16) entirely: no suite here exercises a
  program- or individually-targeted requirement. That gap needs a new suite.
- The last-active-Secretary race guard is recorded **UNTESTED**: the
  single-threaded `php -S` dev server cannot produce real concurrency.
- The audit predates the period confirmation guard (commit `3bc6e4d`,
  2026-09-15) and drives POSTs with curl, so it never executed the
  `window.confirm()` dialog. `smoke_postcommit.sh` asserts the markup and the
  handler are present; the dialog itself still needs a manual browser check.
