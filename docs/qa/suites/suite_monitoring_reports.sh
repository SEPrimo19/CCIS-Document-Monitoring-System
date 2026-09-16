#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-17..20 Monitoring board + FR-24..25 Reports suite ==="
echo "(runs against whatever state the faculty_flow/review/resubmit pipeline left behind -- computes expectations from the live DB rather than assuming fixed numbers)"

SEC="$QA/mr_sec.jar"
rm -f "$SEC"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null

PERIOD_ID=$(DB "select period_id from academic_periods where is_active=1 limit 1;")

# Expected figures computed straight from the DB, the same way the app does.
EXP_PENDING=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.status='Pending';")
EXP_SUBMITTED=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.status='Submitted';")
EXP_APPROVED=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.status='Approved';")
EXP_REVISED=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.status='Revised';")
EXP_TOTAL=$((EXP_PENDING + EXP_SUBMITTED + EXP_APPROVED + EXP_REVISED))
if [ "$EXP_TOTAL" -gt 0 ]; then EXP_COMPLIANCE=$(( (EXP_APPROVED * 100 + EXP_TOTAL/2) / EXP_TOTAL )); else EXP_COMPLIANCE=0; fi
TODAY=$(date +%Y-%m-%d)
EXP_OVERDUE=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and r.deadline is not null and r.deadline < '$TODAY' and s.status <> 'Approved';")

echo "expected: pending=$EXP_PENDING submitted=$EXP_SUBMITTED approved=$EXP_APPROVED revised=$EXP_REVISED total=$EXP_TOTAL compliance=$EXP_COMPLIANCE overdue=$EXP_OVERDUE"

body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/monitoring")

extract_card() {
  # $1 = <h2> label text, prints the number in the following <p class="status">
  echo "$body" | awk -v label="$1" '
    index($0, "<h2>"label"</h2>") { found=1; next }
    found && /class="status"/ { gsub(/<[^>]*>/,""); gsub(/[^0-9]/,""); print; exit }
  '
}

assert_eq "monitoring board Pending count matches DB" "$EXP_PENDING" "$(extract_card 'Pending')"
assert_eq "monitoring board Submitted count matches DB" "$EXP_SUBMITTED" "$(extract_card 'Submitted')"
assert_eq "monitoring board Approved count matches DB" "$EXP_APPROVED" "$(extract_card 'Approved')"
assert_eq "monitoring board Revised count matches DB" "$EXP_REVISED" "$(extract_card 'Revised')"
assert_eq "monitoring board Compliance Rate matches DB-derived calc" "$EXP_COMPLIANCE" "$(extract_card 'Compliance Rate')"
assert_eq "monitoring board Overdue count matches DB" "$EXP_OVERDUE" "$(extract_card 'Overdue')"

# Overdue highlighting: count "Overdue" labels strictly inside the Compliance
# Matrix section (between its heading and the Submission Search heading) --
# must equal EXP_OVERDUE restricted to rows that actually appear in the
# matrix (every submission does, since the matrix is faculty x requirement
# for the whole period).
matrix_section=$(echo "$body" | sed -n '/Compliance Matrix/,/Submission Search/p')
matrix_overdue_count=$(echo "$matrix_section" | grep -o '<span class="overdue">Overdue</span>' | wc -l)
assert_eq "matrix marks exactly EXP_OVERDUE cells as Overdue" "$EXP_OVERDUE" "$matrix_overdue_count"

# --- FR-19: submission search filters ---
body_f=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/monitoring?faculty_name=Dela+Cruz")
if echo "$body_f" | grep -q "Dela Cruz" && ! echo "$body_f" | sed -n '/Submission Search/,$p' | grep -q "Villanueva"; then
  pass "monitoring faculty_name filter shows only matching faculty in search results"
else
  fail "monitoring faculty_name filter did not correctly scope search results"
fi

body_s=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/monitoring?status=Approved")
approved_rows_shown=$(echo "$body_s" | sed -n '/Submission Search/,$p' | grep -oE '<span class="status-pill status-pill-[a-z]+">[A-Za-z -]+</span>' | grep -c "Approved")
assert_eq "monitoring status=Approved filter shows exactly EXP_APPROVED rows" "$EXP_APPROVED" "$approved_rows_shown"

# --- FR-24/25: Reports page figures agree with monitoring (same active period) ---
report_body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/reports?period_id=$PERIOD_ID")
extract_report_card() {
  echo "$report_body" | awk -v label="$1" '
    index($0, "<h2>"label"</h2>") { found=1; next }
    found && /class="status"/ { gsub(/<[^>]*>/,""); gsub(/[^0-9]/,""); print; exit }
  '
}
assert_eq "reports page Pending count matches DB" "$EXP_PENDING" "$(extract_report_card 'Pending')"
assert_eq "reports page Approved count matches DB" "$EXP_APPROVED" "$(extract_report_card 'Approved')"

# --- Reports: invalid / missing params ---
# Read AdminController::reports() first: periodIdFilterFrom() returns null for
# an id not present in the FULL period list, and the method then falls back to
# the active period, same as if period_id were omitted entirely -- this is
# documented, intentional behavior (never a raw error for a bad period_id),
# distinct from exportCsv()'s stricter 404-on-anything-unrecognized policy.
# So the correct expectation is: falls back to showing the ACTIVE period's
# real figures, not a "no period" placeholder.
code=$(http_code "$SEC" "$BASE/admin/reports?period_id=999999")
if [ "$code" = "200" ]; then pass "reports page with a nonexistent period_id degrades gracefully (200, no crash)"; else fail "reports page with a nonexistent period_id returned unexpected code $code"; fi
body_bad=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/reports?period_id=999999")
active_label=$(DB "select school_year from academic_periods where is_active=1 limit 1;")
if echo "$body_bad" | grep -q "$active_label"; then
  pass "reports page with an invalid period_id falls back to the active period (matches AdminController::reports() design, not a bug)"
else
  fail "reports page with an invalid period_id did not fall back to the active period as the source implies"
fi

# --- CSV export: all 3 kinds, RBAC already covered elsewhere; verify content here ---
csv_status=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/reports/export?report=status&period_id=$PERIOD_ID")
echo "$csv_status" | grep -q "^Status,Count" && pass "status CSV has expected header" || fail "status CSV header missing/wrong"
csv_pending_val=$(echo "$csv_status" | grep "^Pending," | cut -d, -f2 | tr -d '\r')
assert_eq "status CSV Pending row matches DB" "$EXP_PENDING" "$csv_pending_val"
csv_approved_val=$(echo "$csv_status" | grep "^Approved," | cut -d, -f2 | tr -d '\r')
assert_eq "status CSV Approved row matches DB" "$EXP_APPROVED" "$csv_approved_val"

csv_faculty=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/reports/export?report=faculty&period_id=$PERIOD_ID")
# PHP's fputcsv() quotes any field containing a space (RFC4180-legal, confirmed
# by direct test: `php -r 'fputcsv(...)'` on this host quotes "Compliance %"
# and "Document Type" the same way) -- strip quotes before comparing headers.
if echo "$csv_faculty" | head -1 | tr -d '"' | grep -q "^Faculty,Total,Pending,Submitted,Approved,Revised,Compliance %"; then
  pass "faculty CSV has expected header"
else
  fail "faculty CSV header missing/wrong (got: $(echo "$csv_faculty" | head -1))"
fi
delacruz_total=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.faculty_id=2;")
csv_delacruz_total=$(echo "$csv_faculty" | grep "^\"\?Juan Dela Cruz" | head -1 | awk -F',' '{gsub(/"/,"",$2); print $2}')
assert_eq "faculty CSV total for Juan Dela Cruz matches DB" "$delacruz_total" "$csv_delacruz_total"

csv_doctype=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/reports/export?report=doctype&period_id=$PERIOD_ID")
if echo "$csv_doctype" | head -1 | tr -d '"' | grep -q "^Document Type,Total,Pending,Submitted,Approved,Revised,Completion %"; then
  pass "doctype CSV has expected header"
else
  fail "doctype CSV header missing/wrong (got: $(echo "$csv_doctype" | head -1))"
fi

# Invalid report kind / missing period -> app documents this as a 404 (see
# AdminController::exportCsv, which calls notFoundPage() rather than guessing).
code=$(http_code "$SEC" "$BASE/admin/reports/export?report=bogus&period_id=$PERIOD_ID")
assert_eq "export with invalid report kind -> 404" "404" "$code"
code=$(http_code "$SEC" "$BASE/admin/reports/export?report=status&period_id=999999")
assert_eq "export with nonexistent period_id -> 404" "404" "$code"
code=$(http_code "$SEC" "$BASE/admin/reports/export?report=status")
assert_eq "export with missing period_id -> 404" "404" "$code"
code=$(http_code "$SEC" "$BASE/admin/reports/export?period_id=$PERIOD_ID")
assert_eq "export with missing report kind -> 404" "404" "$code"

result_line
