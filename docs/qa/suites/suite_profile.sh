#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"

echo "=== FR-5 Profile suite ==="

jar="$QA/p_fac3.jar"
rm -f "$jar"
login "$jar" "faculty3@nwssu.edu.ph" "Faculty@123" > /dev/null

# --- Baseline: fetch current profile values ---
csrf=$(get_csrf "$jar" "/profile")

# --- 1) Change name only (no email change) must NOT require current_password ---
before_email=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select email from users where email='faculty3@nwssu.edu.ph';")
resp=$(curl -s -c "$jar" -b "$jar" \
  --data-urlencode "first_name=Maria" \
  --data-urlencode "last_name=Santos-Updated" \
  --data-urlencode "email=faculty3@nwssu.edu.ph" \
  --data-urlencode "csrf_token=$csrf" \
  -w "\nHTTPCODE:%{http_code}" \
  "$BASE/profile")
code=$(echo "$resp" | grep -o 'HTTPCODE:[0-9]*' | cut -d: -f2)
assert_eq "name-only change (no email change) -> 302 without current_password" "302" "$code"
db_name=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select last_name from users where email='faculty3@nwssu.edu.ph';")
assert_eq "DB reflects updated last_name" "Santos-Updated" "$db_name"

# --- 2) Change email WITHOUT current_password must be rejected ---
csrf=$(get_csrf "$jar" "/profile")
body=$(curl -s -c "$jar" -b "$jar" \
  --data-urlencode "first_name=Maria" \
  --data-urlencode "last_name=Santos-Updated" \
  --data-urlencode "email=faculty3-newmail@nwssu.edu.ph" \
  --data-urlencode "csrf_token=$csrf" \
  "$BASE/profile")
if echo "$body" | grep -qi "current password"; then pass "email change without current_password is rejected with a message"; else fail "email change without current_password was NOT rejected"; fi
db_email=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select email from users where user_id=4;")
assert_eq "DB email unchanged after rejected email-change attempt" "faculty3@nwssu.edu.ph" "$db_email"

# --- 3) Change email WITH WRONG current_password must be rejected ---
csrf=$(get_csrf "$jar" "/profile")
body=$(curl -s -c "$jar" -b "$jar" \
  --data-urlencode "first_name=Maria" \
  --data-urlencode "last_name=Santos-Updated" \
  --data-urlencode "email=faculty3-newmail@nwssu.edu.ph" \
  --data-urlencode "current_password=WrongPassword1" \
  --data-urlencode "csrf_token=$csrf" \
  "$BASE/profile")
if echo "$body" | grep -qi "not your current password"; then pass "email change with WRONG current_password rejected"; else fail "email change with wrong current_password NOT rejected"; fi
db_email=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select email from users where user_id=4;")
assert_eq "DB email still unchanged after wrong-password email-change attempt" "faculty3@nwssu.edu.ph" "$db_email"

# --- 4) Change email WITH CORRECT current_password succeeds ---
csrf=$(get_csrf "$jar" "/profile")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" \
  --data-urlencode "first_name=Maria" \
  --data-urlencode "last_name=Santos-Updated" \
  --data-urlencode "email=faculty3-newmail@nwssu.edu.ph" \
  --data-urlencode "current_password=Faculty@123" \
  --data-urlencode "csrf_token=$csrf" \
  "$BASE/profile")
assert_eq "email change WITH correct current_password -> 302" "302" "$code"
db_email=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select email from users where user_id=4;")
assert_eq "DB email updated after correct-password email change" "faculty3-newmail@nwssu.edu.ph" "$db_email"

# --- 5) Privilege escalation: forged role_id / status in profile POST must not change role/status ---
csrf=$(get_csrf "$jar" "/profile")
before_role=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select role_id,status from users where user_id=4;")
curl -s -o /dev/null -c "$jar" -b "$jar" \
  --data-urlencode "first_name=Maria" \
  --data-urlencode "last_name=Santos-Updated" \
  --data-urlencode "email=faculty3-newmail@nwssu.edu.ph" \
  --data-urlencode "role_id=1" \
  --data-urlencode "status=inactive" \
  --data-urlencode "csrf_token=$csrf" \
  "$BASE/profile"
after_role=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select role_id,status from users where user_id=4;")
assert_eq "forged role_id/status in profile POST has no effect" "$before_role" "$after_role"

# --- 6) Password rules ---
# 6a: too short (< 8 bytes)
csrf=$(get_csrf "$jar" "/profile")
body=$(curl -s -c "$jar" -b "$jar" --data-urlencode "current_password=Faculty@123" --data-urlencode "new_password=Ab1@567" --data-urlencode "confirm_password=Ab1@567" --data-urlencode "csrf_token=$csrf" "$BASE/profile/password")
if echo "$body" | grep -qi "between 8 and 72"; then pass "password < 8 bytes rejected"; else fail "password < 8 bytes NOT rejected"; fi

# 6b: too long (> 72 bytes)
csrf=$(get_csrf "$jar" "/profile")
longpw=$(python3 -c "print('A'*73)" 2>/dev/null || printf 'A%.0s' {1..73})
body=$(curl -s -c "$jar" -b "$jar" --data-urlencode "current_password=Faculty@123" --data-urlencode "new_password=$longpw" --data-urlencode "confirm_password=$longpw" --data-urlencode "csrf_token=$csrf" "$BASE/profile/password")
if echo "$body" | grep -qi "between 8 and 72"; then pass "password > 72 bytes rejected"; else fail "password > 72 bytes NOT rejected"; fi

# 6c: confirm mismatch
csrf=$(get_csrf "$jar" "/profile")
body=$(curl -s -c "$jar" -b "$jar" --data-urlencode "current_password=Faculty@123" --data-urlencode "new_password=NewPassw0rd!" --data-urlencode "confirm_password=Different1!" --data-urlencode "csrf_token=$csrf" "$BASE/profile/password")
if echo "$body" | grep -qi "do not match"; then pass "confirm mismatch rejected"; else fail "confirm mismatch NOT rejected"; fi

# 6d: new same as current
csrf=$(get_csrf "$jar" "/profile")
body=$(curl -s -c "$jar" -b "$jar" --data-urlencode "current_password=Faculty@123" --data-urlencode "new_password=Faculty@123" --data-urlencode "confirm_password=Faculty@123" --data-urlencode "csrf_token=$csrf" "$BASE/profile/password")
if echo "$body" | grep -qi "different from your current"; then pass "new password same as current rejected"; else fail "new password same as current NOT rejected"; fi

# 6e: wrong current password rejected
csrf=$(get_csrf "$jar" "/profile")
body=$(curl -s -c "$jar" -b "$jar" --data-urlencode "current_password=WrongOne1!" --data-urlencode "new_password=NewPassw0rd!" --data-urlencode "confirm_password=NewPassw0rd!" --data-urlencode "csrf_token=$csrf" "$BASE/profile/password")
if echo "$body" | grep -qi "not your current password"; then pass "wrong current_password on password change rejected"; else fail "wrong current_password on password change NOT rejected"; fi

# 6f: valid change succeeds (boundary: exactly 8 bytes)
csrf=$(get_csrf "$jar" "/profile")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" --data-urlencode "current_password=Faculty@123" --data-urlencode "new_password=Ab1@5678" --data-urlencode "confirm_password=Ab1@5678" --data-urlencode "csrf_token=$csrf" "$BASE/profile/password")
assert_eq "valid 8-byte password change -> 302" "302" "$code"

# verify new password actually works for login
jar2="$QA/p_fac3_relogin.jar"
code=$(login "$jar2" "faculty3-newmail@nwssu.edu.ph" "Ab1@5678")
assert_eq "re-login with new email + new password succeeds -> 302" "302" "$code"

# --- 7) CSRF required on profile update and password change ---
jar3="$QA/p_fac2.jar"
rm -f "$jar3"
login "$jar3" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null
curl -s -c "$jar3" -b "$jar3" "$BASE/profile" > /dev/null
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar3" -b "$jar3" --data-urlencode "first_name=Hacked" --data-urlencode "last_name=Name" --data-urlencode "email=faculty2@nwssu.edu.ph" "$BASE/profile")
db_name=$(C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "select first_name from users where email='faculty2@nwssu.edu.ph';")
if [ "$db_name" != "Hacked" ]; then pass "profile update without csrf_token has no effect (name unchanged: $db_name)"; else fail "profile update without csrf_token WAS applied"; fi

code=$(curl -s -o /dev/null -w "%{http_code}" -c "$jar3" -b "$jar3" --data-urlencode "current_password=Faculty@123" --data-urlencode "new_password=ShouldNotApply1" --data-urlencode "confirm_password=ShouldNotApply1" "$BASE/profile/password")
relog=$(login "$QA/p_fac2_check.jar" "faculty2@nwssu.edu.ph" "Faculty@123")
assert_eq "password unchanged without csrf_token (old password still works)" "302" "$relog"

result_line
