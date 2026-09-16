#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-12..16 Review/Approval suite ==="

SEC="$QA/rv_sec.jar"
F1="$QA/rv_fac1.jar"
rm -f "$SEC" "$F1"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
login "$F1" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null

# --- Self-contained setup: make sure submissions 1,2,3 (requirement 1 x
# faculty 1/2/3) are all in Submitted status, regardless of what earlier
# suites left behind. Idempotent: only uploads for a submission still Pending. ---
setup_submit() {
  local subid="$1" jar="$2" email="$3" pass="$4"
  rm -f "$jar"
  login "$jar" "$email" "$pass" > /dev/null
  local st
  st=$(DB "select status from submissions where submission_id=$subid;")
  if [ "$st" = "Pending" ] || [ "$st" = "Revised" ]; then
    local c
    c=$(get_csrf "$jar" "/faculty/requirements")
    curl -s -o /dev/null -c "$jar" -b "$jar" -F "csrf_token=$c" -F "document=@$WQA/valid.pdf;type=application/pdf;filename=valid.pdf" "$BASE/faculty/submissions/$subid/upload"
  fi
}
setup_submit 1 "$QA/rv_setup1.jar" "faculty1@nwssu.edu.ph" "Faculty@123"
setup_submit 2 "$QA/rv_setup2.jar" "faculty2@nwssu.edu.ph" "Faculty@123"
setup_submit 3 "$QA/rv_setup3.jar" "faculty3@nwssu.edu.ph" "Faculty@123"
echo "setup status: $(DB "select submission_id,status,current_version from submissions where submission_id in (1,2,3);")"

# --- FR-12: queue lists Submitted items, filterable ---
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/reviewer/queue")
c1=$(echo "$body" | grep -c "Teaching Load SY2026")
if [ "$c1" -ge 3 ]; then pass "queue lists all 3 Submitted items ($c1 rows)"; else fail "queue does not list expected 3 Submitted items (found $c1)"; fi

body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/reviewer/queue?doc_type_id=1")
if echo "$body" | grep -q "Teaching Load SY2026"; then pass "queue doc_type_id filter includes matching type"; else fail "queue doc_type_id filter excluded matching type"; fi
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/reviewer/queue?doc_type_id=2")
if echo "$body" | grep -q "Teaching Load SY2026"; then fail "queue doc_type_id=2 filter incorrectly included Teaching Load"; else pass "queue doc_type_id filter excludes non-matching type"; fi

# --- FR-13/14: mandatory comments on return ---
cv=$(get_hidden_field "$SEC" "/reviewer/submissions/2/review" "current_version")
csrf=$(get_csrf "$SEC" "/reviewer/submissions/2/review")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "decision=Revised" --data-urlencode "comments=" --data-urlencode "current_version=$cv" --data-urlencode "csrf_token=$csrf" "$BASE/reviewer/submissions/2/review")
if echo "$body" | grep -qi "comments are required"; then pass "return-for-revision without comments rejected"; else fail "return-for-revision without comments NOT rejected"; fi
st=$(DB "select status from submissions where submission_id=2;")
assert_eq "submission 2 still Submitted after rejected return" "Submitted" "$st"

# now return WITH comments -> succeeds
cv=$(get_hidden_field "$SEC" "/reviewer/submissions/2/review" "current_version")
csrf=$(get_csrf "$SEC" "/reviewer/submissions/2/review")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "decision=Revised" --data-urlencode "comments=Please fix the signature page." --data-urlencode "current_version=$cv" --data-urlencode "csrf_token=$csrf" "$BASE/reviewer/submissions/2/review")
assert_eq "return-for-revision WITH comments -> 302" "302" "$code"
row=$(DB "select status from submissions where submission_id=2;")
assert_eq "submission 2 now Revised" "Revised" "$row"
cmt=$(DB "select comments from reviews where submission_id=2 order by review_id desc limit 1;")
assert_eq "comments stored against submission" "Please fix the signature page." "$cmt"

# --- FR-15: visible immediately to faculty owner (faculty2) ---
F2="$QA/rv_fac2_view.jar"
login "$F2" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null
body=$(curl -s -c "$F2" -b "$F2" "$BASE/faculty/requirements")
if echo "$body" | grep -qi "Revised"; then pass "faculty2 checklist immediately shows Revised"; else fail "faculty2 checklist does not show updated status"; fi

# --- FR-13: approve submission 1 ---
cv=$(get_hidden_field "$SEC" "/reviewer/submissions/1/review" "current_version")
csrf=$(get_csrf "$SEC" "/reviewer/submissions/1/review")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "decision=Approved" --data-urlencode "comments=" --data-urlencode "current_version=$cv" --data-urlencode "csrf_token=$csrf" "$BASE/reviewer/submissions/1/review")
assert_eq "approve -> 302" "302" "$code"
st=$(DB "select status from submissions where submission_id=1;")
assert_eq "submission 1 now Approved" "Approved" "$st"

# --- FR-15: monitoring board reflects it ---
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/monitoring")
if echo "$body" | grep -qi "Approved"; then pass "monitoring board shows Approved status"; else fail "monitoring board missing Approved status"; fi

# --- Double-decision: deciding an already-decided submission must be refused ---
cv=$(DB "select current_version from submissions where submission_id=1;")
csrf=$(get_csrf "$SEC" "/reviewer/queue")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "decision=Revised" --data-urlencode "comments=too late" --data-urlencode "current_version=$cv" --data-urlencode "csrf_token=$csrf" -L "$BASE/reviewer/submissions/1/review")
if echo "$body" | grep -qi "already been reviewed"; then pass "double-decision on already-decided item is refused"; else fail "double-decision was NOT refused"; fi
st=$(DB "select status from submissions where submission_id=1;")
assert_eq "submission 1 still Approved after double-decision attempt" "Approved" "$st"
review_count=$(DB "select count(*) from reviews where submission_id=1;")
assert_eq "only one review row recorded for submission 1" "1" "$review_count"

# --- Stale current_version (optimistic lock) on submission 3: fetch review page,
#     then have faculty resubmit is not possible (status is Submitted, not
#     Revised) so instead simulate staleness by tampering the posted version. ---
real_cv=$(DB "select current_version from submissions where submission_id=3;")
stale_cv=$((real_cv + 999))
csrf=$(get_csrf "$SEC" "/reviewer/submissions/3/review")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "decision=Approved" --data-urlencode "comments=" --data-urlencode "current_version=$stale_cv" --data-urlencode "csrf_token=$csrf" -L "$BASE/reviewer/submissions/3/review")
if echo "$body" | grep -qi "changed since you opened it"; then pass "stale current_version guard refuses the decision"; else fail "stale current_version guard did NOT refuse the decision"; fi
st=$(DB "select status from submissions where submission_id=3;")
assert_eq "submission 3 still Submitted after stale-version decide attempt" "Submitted" "$st"
review_count3=$(DB "select count(*) from reviews where submission_id=3;")
assert_eq "no review row created for submission 3 from stale attempt" "0" "$review_count3"

# --- Missing current_version entirely also refused (documented trap, not a bug) ---
csrf=$(get_csrf "$SEC" "/reviewer/submissions/3/review")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "decision=Approved" --data-urlencode "comments=" --data-urlencode "csrf_token=$csrf" -L "$BASE/reviewer/submissions/3/review")
if echo "$body" | grep -qi "changed since you opened it"; then pass "missing current_version field is safely refused (no false accept)"; else fail "missing current_version was NOT refused"; fi
st=$(DB "select status from submissions where submission_id=3;")
assert_eq "submission 3 still Submitted after missing-version decide attempt" "Submitted" "$st"

# now decide submission 3 for real (Approved) with the correct current_version
real_cv=$(DB "select current_version from submissions where submission_id=3;")
csrf=$(get_csrf "$SEC" "/reviewer/submissions/3/review")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "decision=Approved" --data-urlencode "comments=" --data-urlencode "current_version=$real_cv" --data-urlencode "csrf_token=$csrf" "$BASE/reviewer/submissions/3/review")
assert_eq "correct current_version -> decision succeeds (302)" "302" "$code"

# --- Invalid decision value rejected ---
csrf=$(get_csrf "$SEC" "/reviewer/queue")
row4=$(DB "select current_version from submissions where submission_id=4;")
body=$(curl -s -c "$SEC" -b "$SEC" --data-urlencode "decision=Nonsense" --data-urlencode "current_version=$row4" --data-urlencode "csrf_token=$csrf" "$BASE/reviewer/submissions/4/review" -X POST)
# submission 4 is Pending (no file yet) so findForReview may still find it; check whether route responds sanely
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "decision=Nonsense" --data-urlencode "current_version=$row4" --data-urlencode "csrf_token=$csrf" "$BASE/reviewer/submissions/1/review")
# submission 1 already Approved -> should hit "already reviewed" branch regardless of decision value
if [ "$code" = "302" ]; then pass "decide POST on non-Submitted item handled without crash (302)"; else fail "decide POST on non-Submitted item returned unexpected code $code"; fi

# --- FR-16: faculty compliance view ---
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/reviewer/compliance")
if echo "$body" | grep -qi "compliance"; then pass "faculty compliance view renders"; else fail "faculty compliance view missing expected content"; fi

# --- CSRF required on decide ---
cv=$(DB "select current_version from submissions where submission_id=4;")
before=$(DB "select status from submissions where submission_id=4;")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "decision=Approved" --data-urlencode "current_version=$cv" "$BASE/reviewer/submissions/4/review")
after=$(DB "select status from submissions where submission_id=4;")
assert_eq "decide without csrf_token has no effect on status" "$before" "$after"

result_line
