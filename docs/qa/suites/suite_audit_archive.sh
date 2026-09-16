#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-30..34 Audit log + Archive suite ==="

SEC="$QA/au_sec.jar"
rm -f "$SEC"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null

# --- FR-30/31: audit log records exist for the actions exercised by earlier
# suites in this run (login, requirement_publish, submit, resubmit, approve,
# return, doc_type_*, user_*, period_*) -- confirms every state-changing
# controller action in this run actually wrote its audit row, not just that
# the route succeeded. ---
for action in login requirement_publish submit resubmit approve return doc_type_create user_create period_create period_activate; do
  n=$(DB "select count(*) from audit_log where action='$action';")
  if [ "$n" -gt 0 ]; then pass "audit_log has at least one '$action' entry (found $n)"; else fail "audit_log has NO '$action' entry"; fi
done

# --- FR-31: filter by action matches DB exactly ---
exp_submit=$(DB "select count(*) from audit_log where action='submit';")
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/audit-log?action=submit")
shown_submit=$(echo "$body" | grep -o '<span class="action-label">submit</span>' | wc -l)
assert_eq "audit log action=submit filter shows exactly the DB count" "$exp_submit" "$shown_submit"

# --- FR-31: filter by actor (user_id) matches DB exactly ---
sec_id=$(DB "select user_id from users where email='secretary@nwssu.edu.ph';")
exp_sec_actions=$(DB "select count(*) from audit_log where user_id=$sec_id;")
LIMIT=200
if [ "$exp_sec_actions" -gt "$LIMIT" ]; then exp_sec_actions=$LIMIT; fi
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/audit-log?user_id=$sec_id")
rows_shown=$(echo "$body" | grep -oE '<td colspan="6"|<tr>' | grep -c '<tr>')
# subtract 0 -- header <tr> is inside <thead>, count only tbody data rows via a narrower slice
tbody_section=$(echo "$body" | sed -n '/<tbody>/,/<\/tbody>/p')
rows_shown=$(echo "$tbody_section" | grep -c '<tr>')
assert_eq "audit log user_id filter (Secretary) shows exactly the DB count (capped at $LIMIT)" "$exp_sec_actions" "$rows_shown"

# --- date_from/date_to: filtering to a date range that excludes today shows zero rows ---
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/audit-log?date_from=2020-01-01&date_to=2020-01-02")
if echo "$body" | grep -qi "No audit entries match"; then pass "audit log date range excluding today shows the empty-state message"; else fail "audit log date range excluding today did NOT show the empty-state message"; fi

# --- invalid filter values are silently ignored, not 500s ---
code=$(http_code "$SEC" "$BASE/admin/audit-log?action=totally-bogus-action")
assert_eq "audit log with an unrecognized action filter value -> 200 (ignored, not a crash)" "200" "$code"
code=$(http_code "$SEC" "$BASE/admin/audit-log?user_id=abc")
assert_eq "audit log with a non-numeric user_id filter -> 200 (ignored, not a crash)" "200" "$code"
code=$(http_code "$SEC" "$BASE/admin/audit-log?date_from=not-a-date")
assert_eq "audit log with a malformed date_from -> 200 (ignored, not a crash)" "200" "$code"

# --- FR-32/33/34: Archive is read-only (route table has ONLY GET /archive and
# GET /archive/{id} -- confirmed by reading public/index.php; no POST route
# exists under /archive at all) and faculty-scoped. ---
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" -X POST "$BASE/archive/1")
assert_eq "POST /archive/1 has no route -> 404 (archive is read-only by construction)" "404" "$code"

F1="$QA/au_fac1.jar"; F2="$QA/au_fac2.jar"
rm -f "$F1" "$F2"
login "$F1" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null
login "$F2" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null
PERIOD_ID=$(DB "select period_id from academic_periods where is_active=1 limit 1;")
sec_view=$(curl -s -c "$SEC" -b "$SEC" "$BASE/archive/$PERIOD_ID")
fac1_view=$(curl -s -c "$F1" -b "$F1" "$BASE/archive/$PERIOD_ID")
fac2_view=$(curl -s -c "$F2" -b "$F2" "$BASE/archive/$PERIOD_ID")
sec_rows=$(echo "$sec_view" | sed -n '/<tbody>/,/<\/tbody>/p' | grep -c '<tr>')
fac1_rows=$(echo "$fac1_view" | sed -n '/<tbody>/,/<\/tbody>/p' | grep -c '<tr>')
fac2_rows=$(echo "$fac2_view" | sed -n '/<tbody>/,/<\/tbody>/p' | grep -c '<tr>')
db_total_period_subs=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID;")
db_fac1_subs=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.faculty_id=2;")
db_fac2_subs=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.faculty_id=3;")
assert_eq "Secretary archive view for the period shows ALL submissions" "$db_total_period_subs" "$sec_rows"
assert_eq "faculty1 archive view shows ONLY their own submissions" "$db_fac1_subs" "$fac1_rows"
assert_eq "faculty2 archive view shows ONLY their own submissions" "$db_fac2_subs" "$fac2_rows"
if [ "$fac1_rows" -lt "$sec_rows" ]; then pass "faculty archive view is a strict subset of the Secretary's full view (scoping confirmed, not just equal by coincidence)"; else fail "faculty archive view was NOT smaller than the Secretary's full view -- scoping may not be effective"; fi

result_line
