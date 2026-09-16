#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-18 Dashboard figures (Faculty + Secretary) vs DB ==="

SEC="$QA/db_sec.jar"
F1="$QA/db_fac1.jar"
rm -f "$SEC" "$F1"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
login "$F1" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null

PERIOD_ID=$(DB "select period_id from academic_periods where is_active=1 limit 1;")

extract_card() {
  local body="$1" label="$2"
  echo "$body" | awk -v label="$label" '
    index($0, "<h2>"label"</h2>") { found=1; next }
    found && /class="status"/ { gsub(/<[^>]*>/,""); gsub(/[^0-9]/,""); print; exit }
  '
}

# --- Faculty dashboard: per-faculty status counts for the active period ---
fac_body=$(curl -s -c "$F1" -b "$F1" "$BASE/faculty/dashboard")
for status_pair in "Pending:Pending" "Submitted:Submitted" "Approved:Approved" "Revised:Revised"; do
  card_label="${status_pair%%:*}"; db_status="${status_pair#*:}"
  exp=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.faculty_id=2 and s.status='$db_status';")
  got=$(extract_card "$fac_body" "$card_label")
  assert_eq "faculty1 dashboard '$card_label' count matches DB" "$exp" "$got"
done

# --- Secretary dashboard: Awaiting Review count + period-wide status counts ---
sec_body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/dashboard")
exp_awaiting=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.status='Submitted';")
got_awaiting=$(extract_card "$sec_body" "Awaiting Review")
assert_eq "Secretary dashboard 'Awaiting Review' matches DB Submitted count" "$exp_awaiting" "$got_awaiting"

for status_pair in "Pending:Pending" "Submitted:Submitted" "Approved:Approved" "Revised:Revised"; do
  card_label="${status_pair%%:*}"; db_status="${status_pair#*:}"
  exp=$(DB "select count(*) from submissions s join requirements r on r.requirement_id=s.requirement_id where r.period_id=$PERIOD_ID and s.status='$db_status';")
  got=$(extract_card "$sec_body" "$card_label")
  assert_eq "Secretary dashboard '$card_label' count matches DB (period-wide)" "$exp" "$got"
done

result_line
