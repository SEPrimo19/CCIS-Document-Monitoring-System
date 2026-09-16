#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"

echo "=== FR-1..4 Auth suite ==="

# --- FR-1: valid login succeeds ---
jar="$QA/t_auth_valid.jar"
code=$(login "$jar" "secretary@nwssu.edu.ph" "Secretary@123")
assert_eq "valid secretary login -> 302" "302" "$code"
dash_code=$(http_code "$jar" "$BASE/admin/dashboard")
assert_eq "post-login admin dashboard reachable -> 200" "200" "$dash_code"

# --- FR-1: invalid password rejected, generic message ---
jar="$QA/t_auth_badpw.jar"
rm -f "$jar"
csrf=$(get_csrf "$jar" "/login")
body=$(curl -s -c "$jar" -b "$jar" --data-urlencode "email=secretary@nwssu.edu.ph" --data-urlencode "password=WrongPass1" --data-urlencode "csrf_token=$csrf" "$BASE/login")
if echo "$body" | grep -qF "Invalid email or password."; then pass "wrong password shows generic error"; else fail "wrong password: generic error message not found"; fi
if echo "$body" | grep -qiE "no such user|user not found|incorrect password"; then fail "error message leaks which field was wrong"; else pass "error message does not leak field specificity"; fi

# --- FR-1: unknown email rejected, SAME generic message ---
jar="$QA/t_auth_unknown.jar"
rm -f "$jar"
csrf=$(get_csrf "$jar" "/login")
body2=$(curl -s -c "$jar" -b "$jar" --data-urlencode "email=doesnotexist@nwssu.edu.ph" --data-urlencode "password=WrongPass1" --data-urlencode "csrf_token=$csrf" "$BASE/login")
if echo "$body2" | grep -qF "Invalid email or password."; then pass "unknown email shows same generic error"; else fail "unknown email: generic error message not found"; fi

# --- FR-1 CSRF: POST /login without csrf_token must be rejected (not logged in) ---
jar="$QA/t_auth_nocsrf.jar"
rm -f "$jar"
# prime cookie via GET first
curl -s -c "$jar" -b "$jar" "$BASE/login" > /dev/null
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" --data-urlencode "email=secretary@nwssu.edu.ph" --data-urlencode "password=Secretary@123" "$BASE/login")
# Should NOT be a redirect (302) to dashboard - CSRF missing => re-render login (200) with error, and must not establish session
dash_code=$(http_code "$jar" "$BASE/admin/dashboard")
if [ "$code" != "302" ]; then pass "POST /login without csrf_token not a redirect (code=$code)"; else fail "POST /login without csrf_token returned 302 (should be rejected)"; fi
if [ "$dash_code" = "302" ] || [ "$dash_code" = "200" ]; then
  # 200 would mean it got into admin dashboard without valid session - check body actually is dashboard, not login page redirect chain
  actual=$(curl -s -c "$jar" -b "$jar" "$BASE/admin/dashboard")
  if echo "$actual" | grep -qi "Awaiting Review\|Monitoring\|dashboard"; then
    fail "session established despite missing CSRF token on login POST"
  else
    pass "no session established without CSRF token"
  fi
else
  pass "no session established without CSRF token (dashboard code=$dash_code)"
fi

# --- FR-1 CSRF: POST /login with WRONG csrf_token must be rejected ---
jar="$QA/t_auth_badcsrf.jar"
rm -f "$jar"
curl -s -c "$jar" -b "$jar" "$BASE/login" > /dev/null
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" --data-urlencode "email=secretary@nwssu.edu.ph" --data-urlencode "password=Secretary@123" --data-urlencode "csrf_token=0000000000000000000000000000000000000000000000000000000000000" "$BASE/login")
assert_eq "POST /login with forged csrf_token rejected (not 302)" "true" "$([ "$code" != "302" ] && echo true || echo false)"

# --- FR-4: logout requires CSRF too, and ends session ---
jar="$QA/t_auth_logout.jar"
login "$jar" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null
lcsrf=$(get_hidden_field "$jar" "/faculty/dashboard" "csrf_token")
if [ -z "$lcsrf" ]; then lcsrf=$(get_csrf "$jar" "/profile"); fi
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" --data-urlencode "csrf_token=$lcsrf" "$BASE/logout")
assert_eq "logout with csrf -> 302" "302" "$code"
after=$(http_code "$jar" "$BASE/faculty/dashboard")
assert_eq "faculty dashboard after logout -> redirect (302) to login" "302" "$after"

# --- FR-4: role-appropriate dashboard landing ---
jar="$QA/t_auth_role_dash.jar"
login "$jar" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null
body=$(curl -s -c "$jar" -b "$jar" -L "$BASE/dashboard")
if echo "$body" | grep -qi "My Requirements\|Faculty"; then pass "/dashboard forwards Faculty to faculty landing"; else fail "/dashboard did not show faculty-appropriate content"; fi
# faculty must NOT see admin dashboard
admin_dash_code=$(http_code "$jar" "$BASE/admin/dashboard")
assert_eq "Faculty hitting /admin/dashboard -> 403" "403" "$admin_dash_code"

# --- FR-1: login throttle (use a NON-existent / dedicated email so seed accounts are untouched) ---
jar="$QA/t_auth_throttle.jar"
rm -f "$jar"
THROTTLE_EMAIL="throttle-test-$$@nwssu.edu.ph"
for i in 1 2 3 4 5; do
  csrf=$(get_csrf "$jar" "/login")
  curl -s -o /dev/null -c "$jar" -b "$jar" --data-urlencode "email=$THROTTLE_EMAIL" --data-urlencode "password=WrongPass$i" --data-urlencode "csrf_token=$csrf" "$BASE/login"
done
csrf=$(get_csrf "$jar" "/login")
body3=$(curl -s -c "$jar" -b "$jar" --data-urlencode "email=$THROTTLE_EMAIL" --data-urlencode "password=WrongPass6" --data-urlencode "csrf_token=$csrf" "$BASE/login")
if echo "$body3" | grep -qi "too many failed attempts"; then pass "6th failed attempt within window triggers lockout message"; else fail "lockout message not shown after 5 failures"; fi

result_line
