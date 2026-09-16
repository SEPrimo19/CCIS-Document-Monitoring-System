#!/usr/bin/env bash
# CCIS-DMS post-commit smoke test (non-destructive: GETs + logins only).
BASE="http://localhost:8000"
J="$(dirname "$0")"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "FAIL: $1"; }
assert_eq() { if [ "$2" = "$3" ]; then pass "$1 (got $3)"; else fail "$1 (expected $2, got $3)"; fi; }
assert_has() { if echo "$3" | grep -qF "$2"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
assert_not() { if echo "$3" | grep -qF "$2"; then fail "$1 (found: $2)"; else pass "$1"; fi; }

get_csrf() { curl -s -c "$1" -b "$1" "$BASE$2" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed -E 's/.*value="([^"]*)".*/\1/'; }
login() { rm -f "$1"; local c; c=$(get_csrf "$1" "/login"); curl -s -o /dev/null -w "%{http_code}" -c "$1" -b "$1" \
  --data-urlencode "email=$2" --data-urlencode "password=$3" --data-urlencode "csrf_token=$c" "$BASE/login"; }
code() { local j="$1"; shift; curl -s -o /dev/null -w "%{http_code}" -c "$j" -b "$j" "$@"; }
body() { curl -s -c "$1" -b "$1" "$BASE$2"; }

echo "--- A. Environment ---"
assert_eq "GET /login anon" 200 "$(curl -s -o /dev/null -w '%{http_code}' $BASE/login)"
assert_eq "GET / anon redirects" 302 "$(curl -s -o /dev/null -w '%{http_code}' $BASE/)"
H=$(curl -s -D - -o /dev/null $BASE/login)
assert_has "CSP default-src 'self'" "default-src 'self'" "$H"
assert_not "CSP has no unsafe-inline" "unsafe-inline" "$H"

echo "--- B. Authentication ---"
assert_eq "secretary login" 302 "$(login $J/sec.jar secretary@nwssu.edu.ph 'Secretary@123')"
assert_eq "faculty1 login"  302 "$(login $J/fac.jar faculty1@nwssu.edu.ph 'Faculty@123')"
rm -f $J/bad.jar; C=$(get_csrf $J/bad.jar /login)
BADBODY=$(curl -s -c $J/bad.jar -b $J/bad.jar --data-urlencode "email=secretary@nwssu.edu.ph" \
  --data-urlencode "password=wrongpw" --data-urlencode "csrf_token=$C" "$BASE/login")
assert_has "bad password shows generic error" "Invalid email or password" "$BADBODY"

echo "--- C. RBAC spot-check ---"
assert_eq "secretary /admin/dashboard"  200 "$(code $J/sec.jar $BASE/admin/dashboard)"
assert_eq "faculty  /admin/dashboard"   403 "$(code $J/fac.jar $BASE/admin/dashboard)"
assert_eq "anon     /admin/dashboard"   302 "$(code $J/anon.jar $BASE/admin/dashboard)"
assert_eq "secretary /reviewer/queue"   200 "$(code $J/sec.jar $BASE/reviewer/queue)"
assert_eq "faculty  /reviewer/queue"    403 "$(code $J/fac.jar $BASE/reviewer/queue)"
assert_eq "faculty  /faculty/requirements" 200 "$(code $J/fac.jar $BASE/faculty/requirements)"
assert_eq "secretary /faculty/requirements" 403 "$(code $J/sec.jar $BASE/faculty/requirements)"

echo "--- D. Confirmation guard (commit 3bc6e4d) ---"
P=$(body $J/sec.jar /admin/periods)
assert_has "active period row has data-confirm"  'data-confirm="Close' "$P"
assert_has "close confirm names the period"      'Close &quot;AY 2026-2027, 1st Semester&quot;?' "$P"
assert_has "close confirm says nothing deleted"  "Nothing is deleted" "$P"
assert_has "inactive period has activate confirm" 'data-confirm="Make' "$P"
assert_has "activate confirm names period closed" 'This closes &quot;AY 2026-2027, 1st Semester&quot;' "$P"
assert_not "no inline onclick on periods page"    "onclick=" "$P"
assert_not "no inline style= on periods page"     'style="' "$P"
A=$(curl -s $BASE/assets/js/app.js)
assert_has "app.js has data-confirm handler" "form[data-confirm]" "$A"
assert_has "app.js calls window.confirm"     "window.confirm" "$A"

echo "--- E. Screens needed for Chapter 4 figures ---"
for r in /admin/dashboard /admin/monitoring /admin/reports /admin/requirements /admin/users \
         /admin/document-types /admin/periods /admin/audit-log /reviewer/queue /reviewer/compliance \
         /archive /profile /notifications; do
  assert_eq "secretary GET $r" 200 "$(code $J/sec.jar $BASE$r)"
done
for r in /faculty/dashboard /faculty/requirements /profile /notifications; do
  assert_eq "faculty GET $r" 200 "$(code $J/fac.jar $BASE$r)"
done
# exportCsv REQUIRES report= and period_id=; a bare /admin/reports/export is a
# deliberate 404 (AdminController::exportCsv -> notFoundPage), not a defect.
for k in status faculty doctype; do
  assert_eq "CSV export report=$k" 200 "$(code $J/sec.jar "$BASE/admin/reports/export?report=$k&period_id=1")"
done
CT=$(curl -s -D - -o /dev/null -b $J/sec.jar "$BASE/admin/reports/export?report=faculty&period_id=1" | grep -i "content-type")
assert_has "export is CSV" "csv" "$CT"
CD=$(curl -s -D - -o /dev/null -b $J/sec.jar "$BASE/admin/reports/export?report=faculty&period_id=1" | grep -i "content-disposition")
assert_has "export filename is slug+id only" 'ccis-dms-faculty-compliance-period-1.csv' "$CD"
assert_eq "bare export (no params) 404s"  404 "$(code $J/sec.jar $BASE/admin/reports/export)"
assert_eq "unknown report kind 404s"      404 "$(code $J/sec.jar "$BASE/admin/reports/export?report=evil&period_id=1")"
assert_eq "unknown period 404s"           404 "$(code $J/sec.jar "$BASE/admin/reports/export?report=faculty&period_id=999")"
assert_eq "faculty blocked from export"   403 "$(code $J/fac.jar "$BASE/admin/reports/export?report=faculty&period_id=1")"

echo
echo "RESULT: $PASS passed, $FAIL failed"
