#!/usr/bin/env bash
# Creates the two requirements the faculty_flow/review/resubmit suite pipeline
# depends on, via the REAL Secretary POST /admin/requirements endpoint (not
# raw SQL) so Submission::createPendingForFaculty + notification/audit side
# effects all run for real. Run this once per reseed, before
# suite_faculty_flow.sh / suite_review.sh / suite_resubmit.sh.
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== Fixture setup: publish 2 requirements as Secretary ==="

SEC="$QA/setup_sec.jar"
rm -f "$SEC"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null

FUTURE=$(date -d '+30 days' +%Y-%m-%d 2>/dev/null || date -v+30d +%Y-%m-%d)
NEARFUTURE=$(date -d '+1 days' +%Y-%m-%d 2>/dev/null || date -v+1d +%Y-%m-%d)

csrf=$(get_csrf "$SEC" "/admin/requirements/new")
body=$(curl -s -c "$SEC" -b "$SEC" \
  --data-urlencode "doc_type_id=1" \
  --data-urlencode "title=Teaching Load SY2026" \
  --data-urlencode "description=Fixture requirement 1" \
  --data-urlencode "deadline=$FUTURE" \
  --data-urlencode "csrf_token=$csrf" \
  -w "\nHTTPCODE:%{http_code}" \
  "$BASE/admin/requirements")
code=$(echo "$body" | grep -o 'HTTPCODE:[0-9]*' | cut -d: -f2)
assert_eq "publish requirement 1 (Teaching Load) -> 302" "302" "$code"

csrf=$(get_csrf "$SEC" "/admin/requirements/new")
body=$(curl -s -c "$SEC" -b "$SEC" \
  --data-urlencode "doc_type_id=2" \
  --data-urlencode "title=Syllabus SY2026 Overdue Test" \
  --data-urlencode "description=Fixture requirement 2 (backdated after creation to simulate overdue)" \
  --data-urlencode "deadline=$NEARFUTURE" \
  --data-urlencode "csrf_token=$csrf" \
  -w "\nHTTPCODE:%{http_code}" \
  "$BASE/admin/requirements")
code=$(echo "$body" | grep -o 'HTTPCODE:[0-9]*' | cut -d: -f2)
assert_eq "publish requirement 2 (Syllabus) -> 302" "302" "$code"

req_count=$(DB "select count(*) from requirements;")
assert_eq "2 requirements now exist" "2" "$req_count"

sub_count=$(DB "select count(*) from submissions;")
assert_eq "6 submissions auto-created (2 requirements x 3 active faculty)" "6" "$sub_count"

# Backdate requirement 2's deadline directly in the DB to simulate a
# requirement whose deadline has since elapsed in production. This is NOT
# achievable through the UI/API (RequirementController::validate() correctly
# rejects a past deadline at creation time -- verified by source read) so a
# direct SQL UPDATE is the only way to construct this realistic state for
# testing the overdue-highlighting / overdue-reminder logic (FR-20, FR-21).
DB "update requirements set deadline='2026-08-01' where title='Syllabus SY2026 Overdue Test';" > /dev/null
new_deadline=$(DB "select deadline from requirements where title='Syllabus SY2026 Overdue Test';")
assert_eq "requirement 2 deadline backdated to 2026-08-01" "2026-08-01" "$new_deadline"

echo "req/submission ids:"
DB "select requirement_id,title,deadline from requirements order by requirement_id;"
DB "select submission_id,requirement_id,faculty_id,status from submissions order by submission_id;"

result_line
