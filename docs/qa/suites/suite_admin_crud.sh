#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-26..29 Admin CRUD suite (users, document types, periods) ==="

SEC="$QA/cr_sec.jar"
rm -f "$SEC"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null

# ============ Document Types (FR-27) ============
csrf=$(get_csrf "$SEC" "/admin/document-types/new")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" \
  --data-urlencode "name=QA Fixture Type" --data-urlencode "description=x" --data-urlencode "csrf_token=$csrf" \
  "$BASE/admin/document-types")
assert_eq "create document type -> 302" "302" "$code"
dt_id=$(DB "select doc_type_id from document_types where name='QA Fixture Type';")
if [ -z "$dt_id" ]; then fail "created document type not found in DB"; else pass "created document type found in DB (id=$dt_id)"; fi

# duplicate name rejected
csrf=$(get_csrf "$SEC" "/admin/document-types/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "name=QA Fixture Type" --data-urlencode "csrf_token=$csrf" "$BASE/admin/document-types")
if echo "$body" | grep -qi "already exists"; then pass "duplicate document type name rejected"; else fail "duplicate document type name NOT rejected"; fi
dup_count=$(DB "select count(*) from document_types where name='QA Fixture Type';")
assert_eq "no duplicate row created for document type name" "1" "$dup_count"

# name too short (<2 chars) rejected
csrf=$(get_csrf "$SEC" "/admin/document-types/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "name=A" --data-urlencode "csrf_token=$csrf" "$BASE/admin/document-types")
if echo "$body" | grep -qi "between 2 and 80"; then pass "document type name too short rejected"; else fail "document type name too short NOT rejected"; fi

# description too long (>255) rejected
csrf=$(get_csrf "$SEC" "/admin/document-types/new")
longdesc=$(printf 'x%.0s' $(seq 1 256))
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "name=QA Another Type" --data-urlencode "description=$longdesc" --data-urlencode "csrf_token=$csrf" "$BASE/admin/document-types")
if echo "$body" | grep -qi "255 characters or fewer"; then pass "document type description >255 chars rejected"; else fail "document type description >255 chars NOT rejected"; fi

# CSRF required
csrf_before=$(DB "select count(*) from document_types;")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "name=No CSRF Type" "$BASE/admin/document-types"
csrf_after=$(DB "select count(*) from document_types;")
assert_eq "document type create without csrf_token has no effect" "$csrf_before" "$csrf_after"

# deactivate / reactivate + deactivated type excluded from requirement form options
csrf=$(get_csrf "$SEC" "/admin/document-types")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/document-types/$dt_id/deactivate"
is_active=$(DB "select is_active from document_types where doc_type_id=$dt_id;")
assert_eq "document type deactivated in DB" "0" "$is_active"
req_form=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/requirements/new")
if echo "$req_form" | grep -q "QA Fixture Type"; then fail "deactivated document type still offered on the requirement-publish form"; else pass "deactivated document type excluded from requirement-publish form"; fi

csrf=$(get_csrf "$SEC" "/admin/document-types")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/document-types/$dt_id/activate"
is_active=$(DB "select is_active from document_types where doc_type_id=$dt_id;")
assert_eq "document type reactivated in DB" "1" "$is_active"

# ============ Users (FR-26) ============
csrf=$(get_csrf "$SEC" "/admin/users/new")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" \
  --data-urlencode "first_name=QA" --data-urlencode "last_name=Fixture" \
  --data-urlencode "email=qa-fixture@nwssu.edu.ph" --data-urlencode "role_id=2" \
  --data-urlencode "password=Fixture@123" --data-urlencode "csrf_token=$csrf" \
  "$BASE/admin/users")
assert_eq "create user -> 302" "302" "$code"
new_user_id=$(DB "select user_id from users where email='qa-fixture@nwssu.edu.ph';")
if [ -z "$new_user_id" ]; then fail "created user not found in DB"; else pass "created user found in DB (id=$new_user_id)"; fi

# duplicate email rejected
csrf=$(get_csrf "$SEC" "/admin/users/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "first_name=Dup" --data-urlencode "last_name=User" --data-urlencode "email=qa-fixture@nwssu.edu.ph" --data-urlencode "role_id=2" --data-urlencode "password=Fixture@123" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users")
if echo "$body" | grep -qi "already exists"; then pass "duplicate user email rejected"; else fail "duplicate user email NOT rejected"; fi
dup_users=$(DB "select count(*) from users where email='qa-fixture@nwssu.edu.ph';")
assert_eq "no duplicate user row created" "1" "$dup_users"

# invalid email format rejected
csrf=$(get_csrf "$SEC" "/admin/users/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "first_name=Bad" --data-urlencode "last_name=Email" --data-urlencode "email=not-an-email" --data-urlencode "role_id=2" --data-urlencode "password=Fixture@123" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users")
if echo "$body" | grep -qi "valid email"; then pass "invalid email format rejected"; else fail "invalid email format NOT rejected"; fi

# password too short rejected
csrf=$(get_csrf "$SEC" "/admin/users/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "first_name=Short" --data-urlencode "last_name=Pw" --data-urlencode "email=qa-shortpw@nwssu.edu.ph" --data-urlencode "role_id=2" --data-urlencode "password=abc12" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users")
if echo "$body" | grep -qi "between 8 and 72"; then pass "user creation password <8 chars rejected"; else fail "user creation password <8 chars NOT rejected"; fi

# Self-lockout: Secretary cannot deactivate own account
me_id=$(DB "select user_id from users where email='secretary@nwssu.edu.ph';")
csrf=$(get_csrf "$SEC" "/admin/users")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users/$me_id/deactivate"
me_status=$(DB "select status from users where user_id=$me_id;")
assert_eq "Secretary cannot deactivate their own account" "active" "$me_status"

# Preserve last Secretary: deactivating the ONLY active Secretary must fail even when
# attempted against a DIFFERENT account than the actor -- there is only one Secretary
# in the seed, so this IS "the last one"; confirm the guard, not the self-lockout path,
# is what's actually stopping it, by having it attempt on itself is already covered above.
# (No second Secretary account exists to test "attempt via another Secretary session";
# noted as a coverage gap below.)

# Role-change safety: attempt to change the Secretary's own role_id to Faculty (2) via update
csrf=$(get_csrf "$SEC" "/admin/users/$me_id/edit")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "first_name=Sec" --data-urlencode "last_name=Retary" --data-urlencode "email=secretary@nwssu.edu.ph" --data-urlencode "role_id=2" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users/$me_id")
if echo "$body" | grep -qi "cannot change your own role"; then pass "Secretary cannot change their own role away from Secretary"; else fail "Secretary's own-role-change guard did NOT trigger"; fi
role_after=$(DB "select role_id from users where user_id=$me_id;")
assert_eq "Secretary role unchanged in DB" "1" "$role_after"

# Forged role_id/status via user UPDATE by an admin on THEMSELVES with a valid role_id=1 (no-op) should still work normally
csrf=$(get_csrf "$SEC" "/admin/users/$me_id/edit")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "first_name=Secretary" --data-urlencode "last_name=User" --data-urlencode "email=secretary@nwssu.edu.ph" --data-urlencode "role_id=1" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users/$me_id")
assert_eq "no-op self role_id=1 (unchanged) update succeeds -> 302" "302" "$code"

# CSRF required on user create
csrf_before=$(DB "select count(*) from users;")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "first_name=No" --data-urlencode "last_name=Csrf" --data-urlencode "email=qa-nocsrf@nwssu.edu.ph" --data-urlencode "role_id=2" --data-urlencode "password=Fixture@123" "$BASE/admin/users"
csrf_after=$(DB "select count(*) from users;")
assert_eq "user create without csrf_token has no effect" "$csrf_before" "$csrf_after"

# Deactivate / reactivate a normal (non-Secretary) user works
csrf=$(get_csrf "$SEC" "/admin/users")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users/$new_user_id/deactivate"
st=$(DB "select status from users where user_id=$new_user_id;")
assert_eq "ordinary faculty user deactivated successfully" "inactive" "$st"
# A deactivated user's session should be terminated on their NEXT request (Guard re-checks DB)
deact_jar="$QA/cr_deact_check.jar"
login "$deact_jar" "qa-fixture@nwssu.edu.ph" "Fixture@123" > /dev/null
code=$(http_code "$deact_jar" "$BASE/faculty/dashboard")
assert_eq "deactivated user's session is rejected on next request -> 302 (login redirect)" "302" "$code"
csrf=$(get_csrf "$SEC" "/admin/users")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/users/$new_user_id/activate"
st=$(DB "select status from users where user_id=$new_user_id;")
assert_eq "ordinary faculty user reactivated successfully" "active" "$st"

# ============ Academic Periods (FR-29) — the single-active-period invariant ============
csrf=$(get_csrf "$SEC" "/admin/periods/new")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "school_year=2030-2031" --data-urlencode "semester=1st" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods")
assert_eq "create a new academic period -> 302" "302" "$code"
new_period_id=$(DB "select period_id from academic_periods where school_year='2030-2031' and semester='1st';")
if [ -z "$new_period_id" ]; then fail "created period not found in DB"; else pass "created period found in DB (id=$new_period_id), created INACTIVE by design"; fi
new_period_active=$(DB "select is_active from academic_periods where period_id=$new_period_id;")
assert_eq "newly created period is inactive by default" "0" "$new_period_active"

# duplicate (school_year, semester) rejected
csrf=$(get_csrf "$SEC" "/admin/periods/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "school_year=2030-2031" --data-urlencode "semester=1st" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods")
if echo "$body" | grep -qi "already exist"; then pass "duplicate (school_year, semester) period rejected"; else fail "duplicate period NOT rejected"; fi

# malformed school_year rejected
csrf=$(get_csrf "$SEC" "/admin/periods/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "school_year=2030" --data-urlencode "semester=1st" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods")
if echo "$body" | grep -qi "YYYY-YYYY"; then pass "malformed school_year rejected"; else fail "malformed school_year NOT rejected"; fi

# non-consecutive years rejected
csrf=$(get_csrf "$SEC" "/admin/periods/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "school_year=2030-2035" --data-urlencode "semester=1st" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods")
if echo "$body" | grep -qi "must follow the first"; then pass "non-consecutive school year pair rejected"; else fail "non-consecutive school year pair NOT rejected"; fi

# end_date before start_date rejected
csrf=$(get_csrf "$SEC" "/admin/periods/new")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "school_year=2031-2032" --data-urlencode "semester=1st" --data-urlencode "start_date=2031-06-01" --data-urlencode "end_date=2031-01-01" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods")
if echo "$body" | grep -qi "cannot be before the start date"; then pass "end_date before start_date rejected"; else fail "end_date before start_date NOT rejected"; fi

# --- The invariant: exactly one active period at all times ---
active_count_before=$(DB "select count(*) from academic_periods where is_active=1;")
assert_eq "exactly one active period before activation test" "1" "$active_count_before"
original_active_id=$(DB "select period_id from academic_periods where is_active=1;")

csrf=$(get_csrf "$SEC" "/admin/periods")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods/$new_period_id/activate"
active_count=$(DB "select count(*) from academic_periods where is_active=1;")
assert_eq "activating a period leaves exactly ONE active period" "1" "$active_count"
now_active=$(DB "select period_id from academic_periods where is_active=1;")
assert_eq "the newly activated period is now the active one" "$new_period_id" "$now_active"

# activate the SAME period again (idempotent repeat activation)
csrf=$(get_csrf "$SEC" "/admin/periods")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods/$new_period_id/activate")
assert_eq "re-activating the already-active period -> 302 (no error)" "302" "$code"
active_count=$(DB "select count(*) from academic_periods where is_active=1;")
assert_eq "still exactly one active period after re-activating the same one" "1" "$active_count"

# activate a NONEXISTENT period id -- must NOT leave zero active periods
csrf=$(get_csrf "$SEC" "/admin/periods")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods/999999/activate")
assert_eq "activating a nonexistent period id -> 404" "404" "$code"
active_count=$(DB "select count(*) from academic_periods where is_active=1;")
assert_eq "activating a nonexistent period id leaves exactly one active period (never zero)" "1" "$active_count"
still_active=$(DB "select period_id from academic_periods where is_active=1;")
assert_eq "the previously-active period is undisturbed by the failed activate-nonexistent attempt" "$new_period_id" "$still_active"

# deactivate the current active period -> zero active periods is a VALID state (per PeriodController::deactivate design)
csrf=$(get_csrf "$SEC" "/admin/periods")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods/$new_period_id/deactivate"
active_count=$(DB "select count(*) from academic_periods where is_active=1;")
assert_eq "deactivating the active period results in zero active periods (allowed, by design)" "0" "$active_count"

# deactivating an ALREADY-inactive period again is a no-op, reported as such
csrf=$(get_csrf "$SEC" "/admin/periods")
body=$(curl -s -c "$SEC" -b "$SEC" -L --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods/$new_period_id/deactivate")
if echo "$body" | grep -qi "already been closed\|already closed"; then pass "double-deactivating a period reports it was already closed"; else fail "double-deactivate did not report already-closed"; fi

# restore the original active period so later suites relying on an active period are undisturbed
csrf=$(get_csrf "$SEC" "/admin/periods")
curl -s -o /dev/null -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" "$BASE/admin/periods/$original_active_id/activate"
restored_active=$(DB "select period_id from academic_periods where is_active=1;")
assert_eq "restored the original active period for downstream suites" "$original_active_id" "$restored_active"

result_line
