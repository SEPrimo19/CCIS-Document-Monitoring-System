#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-6..11 Faculty submission flow ==="

F1="$QA/ff_fac1.jar"; F2="$QA/ff_fac2.jar"
rm -f "$F1" "$F2"
login "$F1" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null
login "$F2" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null

SUB1=$(DB "select submission_id from submissions where faculty_id=2 and requirement_id=1;")   # faculty1, Teaching Load
SUB_OVERDUE=$(DB "select submission_id from submissions where faculty_id=2 and requirement_id=2;") # faculty1, overdue Syllabus
echo "SUB1=$SUB1 SUB_OVERDUE=$SUB_OVERDUE"

# --- FR-6: checklist shows both pending items ---
body=$(curl -s -c "$F1" -b "$F1" "$BASE/faculty/requirements")
if echo "$body" | grep -q "Teaching Load SY2026"; then pass "checklist shows Teaching Load requirement"; else fail "checklist missing Teaching Load requirement"; fi
if echo "$body" | grep -q "Syllabus SY2026 Overdue Test"; then pass "checklist shows Syllabus requirement"; else fail "checklist missing Syllabus requirement"; fi
# FR-20: overdue distinctly highlighted (class name or "Overdue" label)
if echo "$body" | grep -qi "overdue"; then pass "checklist marks overdue requirement (FR-20)"; else fail "checklist does NOT visibly mark the overdue requirement"; fi

# --- FR-8: upload validation ---
csrf=$(get_csrf "$F1" "/faculty/requirements")

# .exe rejected
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$WQA/malicious.exe;type=application/octet-stream;filename=malicious.exe" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qi "only pdf or word"; then pass ".exe upload rejected with explanatory message"; else fail ".exe upload NOT rejected as expected"; fi
st=$(DB "select status from submissions where submission_id=$SUB1;")
assert_eq "status still Pending after .exe rejection" "Pending" "$st"

# text file renamed .pdf rejected (fails MIME sniff)
csrf=$(get_csrf "$F1" "/faculty/requirements")
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$WQA/fake_renamed.pdf;type=application/pdf;filename=fake_renamed.pdf" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qi "does not look like a valid"; then pass "text-file-renamed-.pdf rejected (fails structural/MIME check)"; else fail "renamed text file was NOT rejected"; fi
st=$(DB "select status from submissions where submission_id=$SUB1;")
assert_eq "status still Pending after fake-pdf rejection" "Pending" "$st"

# zero-byte file rejected
csrf=$(get_csrf "$F1" "/faculty/requirements")
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$WQA/zero.pdf;type=application/pdf;filename=zero.pdf" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qiE "10 MB limit|choose a pdf"; then pass "zero-byte file rejected"; else fail "zero-byte file NOT rejected"; fi

# no file at all
csrf=$(get_csrf "$F1" "/faculty/requirements")
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qi "choose a pdf"; then pass "missing file field rejected"; else fail "missing file field NOT rejected as expected"; fi

# oversized file (>10MB) rejected
csrf=$(get_csrf "$F1" "/faculty/requirements")
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$WQA/oversized.pdf;type=application/pdf;filename=oversized.pdf" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qi "10 MB limit"; then pass "oversized (>10MB) file rejected"; else fail "oversized file NOT rejected"; fi
st=$(DB "select status from submissions where submission_id=$SUB1;")
assert_eq "status still Pending after all invalid uploads" "Pending" "$st"

# --- FR-7: valid upload succeeds ---
csrf=$(get_csrf "$F1" "/faculty/requirements")
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$WQA/valid.pdf;type=application/pdf;filename=valid.pdf" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qi "Uploaded"; then pass "valid PDF upload succeeds"; else fail "valid PDF upload did not report success"; fi
st=$(DB "select status,current_version from submissions where submission_id=$SUB1;")
assert_eq "submission is now Submitted, version 1" "Submitted	1" "$st"
filecount=$(DB "select count(*) from document_files where submission_id=$SUB1;")
assert_eq "one document_files row recorded" "1" "$filecount"

# --- Re-upload while already Submitted must be rejected ---
csrf=$(get_csrf "$F1" "/faculty/requirements")
body=$(curl -s -c "$F1" -b "$F1" -F "csrf_token=$csrf" -F "document=@$WQA/valid.pdf;type=application/pdf;filename=valid2.pdf" -L "$BASE/faculty/submissions/$SUB1/upload")
if echo "$body" | grep -qi "be uploaded to right now"; then pass "re-upload while Submitted is rejected"; else fail "re-upload while Submitted was NOT rejected"; fi
st=$(DB "select current_version from submissions where submission_id=$SUB1;")
assert_eq "version unchanged after rejected re-upload" "1" "$st"

# --- FR-9: My Submissions reflected via checklist status ---
body=$(curl -s -c "$F1" -b "$F1" "$BASE/faculty/requirements")
if echo "$body" | grep -qi "Submitted"; then pass "checklist reflects Submitted status"; else fail "checklist does not show Submitted status"; fi

# --- IDOR: faculty2 cannot upload to faculty1's submission (must 404, not 403) ---
csrf2=$(get_csrf "$F2" "/faculty/requirements")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$F2" -b "$F2" -F "csrf_token=$csrf2" -F "document=@$WQA/valid.pdf;type=application/pdf;filename=valid.pdf" "$BASE/faculty/submissions/$SUB1/upload")
assert_eq "faculty2 upload to faculty1's submission -> 404" "404" "$code"

# --- IDOR: faculty2 opening faculty1's submission detail -> 404 ---
code=$(http_code "$F2" "$BASE/submissions/$SUB1")
assert_eq "faculty2 GET /submissions/$SUB1 (faculty1's) -> 404" "404" "$code"
code=$(http_code "$F1" "$BASE/submissions/$SUB1")
assert_eq "faculty1 GET own /submissions/$SUB1 -> 200" "200" "$code"

# --- FR-11: document detail shows metadata / version history ---
body=$(curl -s -c "$F1" -b "$F1" "$BASE/submissions/$SUB1")
if echo "$body" | grep -qi "valid.pdf"; then pass "document detail shows uploaded file name"; else fail "document detail missing uploaded file name"; fi
if echo "$body" | grep -qiE "version|v1"; then pass "document detail shows version info"; else fail "document detail missing version info"; fi

# --- IDOR: download route ---
FILEID=$(DB "select file_id from document_files where submission_id=$SUB1;")
code=$(http_code "$F2" "$BASE/documents/$FILEID/download")
assert_eq "faculty2 downloading faculty1's file -> 404" "404" "$code"
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$F1" -b "$F1" "$BASE/documents/$FILEID/download")
assert_eq "faculty1 downloading own file -> 200" "200" "$code"

result_line
