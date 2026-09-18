#!/usr/bin/env bash
# Security audit suite — authentication, authorization, injection, headers.
#
# SAFE against real data. Every probe is either read-only or an operation the
# application is expected to REFUSE, and each rejection is confirmed by reading
# the state back rather than by trusting the status code. Nothing here creates a
# submission, a user or a file.
#
# Two things it deliberately does NOT do:
#   * throttle-test a real account — the lockout is keyed on email+IP, so the
#     probe uses an address that cannot exist and the seed logins stay usable;
#   * exercise a SUCCESSFUL upload — that would write to the database.
#
# Run it with the app up and the seed logins intact:
#   bash docs/qa/suites/suite_security_audit.sh
. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

A="$QA/sa_anon.jar"; S="$QA/sa_sec.jar"; F1="$QA/sa_f1.jar"; F2="$QA/sa_f2.jar"
rm -f "$A"
login "$S"  secretary@nwssu.edu.ph 'Secretary@123' >/dev/null
login "$F1" faculty1@nwssu.edu.ph  'Faculty@123'   >/dev/null
login "$F2" faculty2@nwssu.edu.ph  'Faculty@123'   >/dev/null

# curl.exe here is a native Windows build: -F "field=@path" needs a Windows-style
# path. cygpath -m gives C:/... which it accepts and which survives quoting far
# better than backslashes (a lost backslash silently becomes curl exit 26 and
# reads as HTTP 000).
W=$(cygpath -m "$QA")

# --- 1. authentication: no protected screen answers an anonymous caller -----
BAD=0
for p in /dashboard /admin/dashboard /faculty/dashboard /admin/document-types /admin/users \
         /admin/periods /admin/requirements /admin/monitoring /admin/audit-log /admin/reports \
         /faculty/requirements /reviewer/queue /reviewer/compliance /reviewer/status/Approved \
         /documents/1 /documents/1/download /documents/1/view /submissions/1 /archive /archive/1 \
         /profile /avatars/2 "/search?q=a" /notifications /health; do
  code=$(http_code "$A" "$BASE$p")
  [ "$code" = "302" ] || { echo "   anonymous reached $p -> $code"; BAD=$((BAD+1)); }
done
[ "$BAD" -eq 0 ] && pass "25 protected routes all redirect an anonymous caller" \
                 || fail "$BAD route(s) answered an anonymous caller"

# --- 2. authorization: Faculty is refused every Secretary-only screen -------
BAD=0
for p in /admin/dashboard /admin/document-types /admin/document-types/new /admin/users \
         /admin/users/new /admin/periods /admin/periods/new /admin/requirements \
         /admin/requirements/new /admin/monitoring /admin/audit-log /admin/reports \
         /reviewer/queue /reviewer/compliance /reviewer/status/Approved \
         /reviewer/submissions/1/review; do
  code=$(http_code "$F1" "$BASE$p")
  [ "$code" = "403" ] || { echo "   faculty reached $p -> $code"; BAD=$((BAD+1)); }
done
[ "$BAD" -eq 0 ] && pass "16 Secretary-only screens answer Faculty with 403" \
                 || fail "$BAD Secretary screen(s) leaked to Faculty"

# --- 3. horizontal access between two faculty accounts ----------------------
FID=$(curl -s -b "$F1" -c "$F1" "$BASE/faculty/requirements" | grep -oE 'href="/documents/[0-9]+"' | head -1 | grep -oE '[0-9]+')
if [ -n "$FID" ]; then
  assert_eq "another faculty cannot open the viewer"   404 "$(http_code "$F2" "$BASE/documents/$FID")"
  assert_eq "another faculty cannot download the file" 404 "$(http_code "$F2" "$BASE/documents/$FID/download")"
  assert_eq "another faculty cannot fetch the bytes"   404 "$(http_code "$F2" "$BASE/documents/$FID/view")"
  assert_eq "the owner still can"                      200 "$(http_code "$F1" "$BASE/documents/$FID")"
  # The same answer for "not yours" and "no such id", or the route becomes an
  # oracle for which file ids exist.
  assert_eq "forbidden is indistinguishable from absent" \
    "$(http_code "$F2" "$BASE/documents/99999")" "$(http_code "$F2" "$BASE/documents/$FID")"
fi
assert_eq "an account with no photo answers like an unknown one" \
  "$(http_code "$F1" "$BASE/avatars/99999")" "$(http_code "$F1" "$BASE/avatars/1")"

# --- 4. CSRF: absent, forged and cross-session tokens change nothing --------
target=$(curl -s -b "$S" -c "$S" "$BASE/admin/users" | grep -oE '/admin/users/[0-9]+/deactivate' | head -1)
before=$(curl -s -b "$S" -c "$S" "$BASE/admin/users" | grep -c 'deactivate')
forged="deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
other_token=$(get_csrf "$F1" "/profile")
curl -s -o /dev/null -c "$S" -b "$S" -d "" "$BASE$target"
curl -s -o /dev/null -c "$S" -b "$S" --data-urlencode "csrf_token=$forged" "$BASE$target"
curl -s -o /dev/null -c "$S" -b "$S" --data-urlencode "csrf_token=$other_token" "$BASE$target"
after=$(curl -s -b "$S" -c "$S" "$BASE/admin/users" | grep -c 'deactivate')
assert_eq "no account changed state across 3 token-less/forged POSTs" "$before" "$after"

# --- 5. session handling ----------------------------------------------------
J="$QA/sa_fix.jar"; rm -f "$J"
curl -s -c "$J" -b "$J" "$BASE/login" >/dev/null
pre=$(grep -i ccisdms_session "$J" | awk '{print $7}')
csrf=$(get_csrf "$J" "/login")
curl -s -o /dev/null -c "$J" -b "$J" --data-urlencode "email=faculty1@nwssu.edu.ph" \
  --data-urlencode "password=Faculty@123" --data-urlencode "csrf_token=$csrf" "$BASE/login"
post=$(grep -i ccisdms_session "$J" | awk '{print $7}')
if [ -n "$pre" ] && [ "$pre" != "$post" ]; then
  pass "the session id is rotated on sign-in"
else
  fail "session fixation: the id survived the sign-in"
fi

hdrs=$(curl -s -D - -o /dev/null -c "$QA/sa_h.jar" "$BASE/login" | grep -i "set-cookie")
echo "$hdrs" | grep -qi "httponly"     && pass "session cookie is HttpOnly"     || fail "session cookie is readable by script"
echo "$hdrs" | grep -qi "samesite=lax" && pass "session cookie is SameSite=Lax" || fail "session cookie has no SameSite"

csrf=$(get_csrf "$F2" "/faculty/requirements")
curl -s -o /dev/null -c "$F2" -b "$F2" --data-urlencode "csrf_token=$csrf" "$BASE/logout"
assert_eq "a logged-out session cannot reach a protected page" 302 "$(http_code "$F2" "$BASE/faculty/requirements")"
login "$F2" faculty2@nwssu.edu.ph 'Faculty@123' >/dev/null

# --- 6. brute force, probed on an address that cannot exist -----------------
PJ="$QA/sa_brute.jar"; locked="no"
for i in 1 2 3 4 5 6 7; do
  rm -f "$PJ"
  c=$(get_csrf "$PJ" "/login")
  body=$(curl -s -c "$PJ" -b "$PJ" --data-urlencode "email=audit-probe@example.invalid" \
        --data-urlencode "password=wrong-$i" --data-urlencode "csrf_token=$c" "$BASE/login")
  echo "$body" | grep -qi "too many failed attempts" && locked="yes"
done
[ "$locked" = "yes" ] && pass "repeated failures are locked out" || fail "no lockout after 7 failed sign-ins"

# --- 7. injection ------------------------------------------------------------
BEFORE=$(curl -s -b "$S" -c "$S" "$BASE/admin/monitoring" | grep -c '<tr>')
BAD=0
for payload in "%27%20OR%20%271%27%3D%271" "%27%3B%20DROP%20TABLE%20users%3B%20--" \
               "1%27%20UNION%20SELECT%20NULL--" "%5C" "1%20AND%20SLEEP(3)" "admin%27--"; do
  for t in "/search?q=" "/admin/monitoring?faculty=" "/admin/monitoring?status=" \
           "/reviewer/status/" "/admin/reports?period_id=" "/admin/dashboard?month="; do
    case "$(http_code "$S" "$BASE$t$payload")" in
      5??) echo "   $t$payload -> 5xx"; BAD=$((BAD+1)) ;;
    esac
  done
done
AFTER=$(curl -s -b "$S" -c "$S" "$BASE/admin/monitoring" | grep -c '<tr>')
if [ "$BAD" -eq 0 ] && [ "$BEFORE" = "$AFTER" ]; then
  pass "36 injection probes over 6 parameters: no 5xx, no row-count change"
else
  fail "$BAD probe(s) errored, or the data changed ($BEFORE -> $AFTER)"
fi

XSS='<script>alert(1)</script>'
if curl -s -b "$S" -c "$S" --get --data-urlencode "q=$XSS" "$BASE/search" | grep -qF "$XSS"; then
  fail "the search term is reflected as live markup"
else
  pass "the search term is escaped on the way back out"
fi

# --- 8. traversal and direct access to source or secrets --------------------
BAD=0
for p in "/documents/1/media/..%2F..%2F.env" "/documents/1/media/word%2Fdocument.xml" \
         "/avatars/..%2F..%2F.env" "/.env" "/app/Core/Auth.php" "/config/config.php" \
         "/database/seed.sql" "/.git/config" "/storage/uploads/"; do
  [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$S" -c "$S" "$BASE$p")" = "200" ] && {
    echo "   $p -> 200"; BAD=$((BAD+1)); }
done
[ "$BAD" -eq 0 ] && pass "9 traversal / direct-source requests all refused" \
                 || fail "$BAD path(s) returned content that should not be reachable"

# --- 9. the upload validators refuse hostile files --------------------------
SUB=$(curl -s -b "$F1" -c "$F1" "$BASE/faculty/requirements" | grep -oE '/faculty/submissions/[0-9]+/upload' | head -1)
docs_before=$(curl -s -b "$F1" -c "$F1" "$BASE/faculty/requirements" | grep -c 'Download')
for f in malicious.exe fake_renamed.pdf oversized.pdf; do
  csrf=$(get_csrf "$F1" "/faculty/requirements")
  curl -s -o /dev/null -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$W/$f" "$BASE$SUB"
done
docs_after=$(curl -s -b "$F1" -c "$F1" "$BASE/faculty/requirements" | grep -c 'Download')
assert_eq "3 hostile documents rejected, no new version stored" "$docs_before" "$docs_after"

if [ -d "$QA/avatar_fx" ]; then
  for f in evil.svg shell.png realjpeg_named_png.png toobig_px.png empty.png; do
    csrf=$(get_csrf "$F1" "/profile")
    curl -s -o /dev/null -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "photo=@$W/avatar_fx/$f" "$BASE/profile/photo"
  done
  # An SVG accepted here would be stored XSS served from our own origin.
  store="$(dirname "${BASH_SOURCE[0]}")/../../storage/avatars"
  stored=$(ls "$store" 2>/dev/null | grep -cv gitignore)
  assert_eq "5 hostile images rejected, nothing written to the avatar store" 0 "$stored"
fi

# --- 10. privilege escalation through POST bodies ---------------------------
csrf=$(get_csrf "$F1" "/faculty/requirements")
assert_eq "faculty cannot POST a review decision" 403 \
  "$(curl -s -o /dev/null -w "%{http_code}" -c "$F1" -b "$F1" \
     --data-urlencode "csrf_token=$csrf" --data-urlencode "decision=Approved" \
     --data-urlencode "current_version=1" "$BASE/reviewer/submissions/1/review")"

OTHER=$(curl -s -b "$F2" -c "$F2" "$BASE/faculty/requirements" | grep -oE '/faculty/submissions/[0-9]+/upload' | head -1)
csrf=$(get_csrf "$F1" "/faculty/requirements")
assert_eq "faculty cannot upload into another account's submission" 404 \
  "$(curl -s -o /dev/null -w "%{http_code}" -c "$F1" -b "$F1" \
     -F "csrf_token=$csrf" -F "document=@$W/valid.pdf" "$BASE$OTHER")"

# Role, status and program are Secretary-set. They are absent from the
# self-service UPDATE, so a forged field has nothing to bind to — but the proof
# is that the account's access does not change.
csrf=$(get_csrf "$F1" "/profile")
curl -s -o /dev/null -c "$F1" -b "$F1" --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "first_name=Juan" --data-urlencode "last_name=Dela Cruz" \
  --data-urlencode "role_id=1" --data-urlencode "role_name=Secretary" \
  --data-urlencode "status=Active" --data-urlencode "program_id=1" "$BASE/profile"
assert_eq "forging role/status/program in the profile POST grants nothing" 403 \
  "$(http_code "$F1" "$BASE/admin/users")"

# --- 11. response headers ----------------------------------------------------
h=$(curl -s -D - -o /dev/null -b "$S" -c "$S" "$BASE/admin/monitoring")
for want in "X-Content-Type-Options: nosniff" "X-Frame-Options: DENY" "Referrer-Policy" "Content-Security-Policy"; do
  echo "$h" | grep -qi "$want" && pass "page sends $want" || fail "page is missing $want"
done
# The one route allowed to be framed, and only it.
hp=$(curl -s -D - -o /dev/null -b "$S" -c "$S" "$BASE/documents/1/view")
echo "$hp" | grep -qi "x-frame-options: SAMEORIGIN" && pass "the PDF route relaxes to SAMEORIGIN" \
                                                    || fail "the PDF route does not carry SAMEORIGIN"
# A version banner tells an attacker which CVEs to try first.
if echo "$h" | grep -qi "^X-Powered-By"; then
  fail "X-Powered-By discloses the PHP version"
else
  pass "no X-Powered-By version banner"
fi

# --- 12. errors must not leak internals -------------------------------------
BAD=0
for p in "/documents/abc" "/documents/-1" "/admin/users/99999/edit" "/reviewer/status/NotAStatus" "/no-such-page"; do
  curl -s -b "$S" -c "$S" "$BASE$p" | grep -qiE "fatal error|stack trace|PDOException|SQLSTATE|on line [0-9]+" && {
    echo "   internals leaked by $p"; BAD=$((BAD+1)); }
done
[ "$BAD" -eq 0 ] && pass "5 malformed URLs leak no PHP, path or SQL detail" \
                 || fail "$BAD URL(s) leaked internals"

# --- 13. Apache config (static check — the dev server never reads .htaccess) -
# This is the one part of the stack these suites CANNOT exercise at runtime:
# the PHP dev server ignores .htaccess entirely, so a rule that breaks the app
# on Apache passes every live test here. It is checked by reading instead.
HT="$(dirname "${BASH_SOURCE[0]}")/../../public/.htaccess"
if [ -f "$HT" ]; then
  # Unscoped Header directives also apply to PHP responses, and `always` merges
  # with what PHP already sent rather than replacing it — two X-Frame-Options on
  # one response, which a browser resolves as "deny". That breaks the one route
  # allowed to be framed (the document viewer) on Apache only.
  if grep -q 'FilesMatch' "$HT" &&      [ "$(grep -c 'Header always set' "$HT")" -gt 0 ] &&      [ "$(sed -n '/<FilesMatch/,/<\/FilesMatch>/p' "$HT" | grep -c 'Header always set')"        = "$(grep -c 'Header always set' "$HT")" ]; then
    pass "every Apache header directive is scoped to static files"
  else
    fail "a Header directive in public/.htaccess is unscoped — it will clobber the framed route on Apache"
  fi
  grep -q 'Options -Indexes' "$HT" && pass "directory listing is off" || fail "public/.htaccess does not disable indexes"
fi

# The project-root .htaccess is the safety net for a mis-set DocumentRoot: with
# it, that mistake fails closed instead of serving .env and storage/uploads/.
ROOT_HT="$(dirname "${BASH_SOURCE[0]}")/../../.htaccess"
if grep -qE 'Require all denied|Deny from all' "$ROOT_HT" 2>/dev/null; then
  pass "a mis-set DocumentRoot fails closed"
else
  fail "the project-root .htaccess no longer denies everything"
fi

result_line
