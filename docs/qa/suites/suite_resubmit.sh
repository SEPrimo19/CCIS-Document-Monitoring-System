#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-10 Resubmission / versioning suite ==="
echo "(depends on suite_review.sh having marked submission_id=2 Revised)"

F2="$QA/rs_fac2.jar"
rm -f "$F2"
login "$F2" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null

before_status=$(DB "select status from submissions where submission_id=2;")
assert_eq "precondition: submission 2 is Revised" "Revised" "$before_status"

# Create a distinguishable second-version file (bash heredoc -- avoids
# python's own POSIX-vs-Windows path confusion on this host).
printf '%%PDF-1.4\n%%v2 revised content\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%%%EOF' > "$QA/valid_v2.pdf"

csrf=$(get_csrf "$F2" "/faculty/requirements")
body=$(curl -s -c "$F2" -b "$F2" -F "csrf_token=$csrf" -F "document=@$WQA/valid_v2.pdf;type=application/pdf;filename=valid_v2.pdf" -L "$BASE/faculty/submissions/2/upload")
if echo "$body" | grep -qi "Uploaded"; then pass "resubmission upload succeeds"; else fail "resubmission upload did not report success"; fi

row=$(DB "select status,current_version from submissions where submission_id=2;")
assert_eq "submission 2 back to Submitted, version 2" "Submitted	2" "$row"

filecount=$(DB "select count(*) from document_files where submission_id=2;")
assert_eq "two document_files rows now exist (prior version preserved)" "2" "$filecount"

v1name=$(DB "select file_name from document_files where submission_id=2 and version_no=1;")
v2name=$(DB "select file_name from document_files where submission_id=2 and version_no=2;")
assert_eq "version 1 file name preserved" "valid.pdf" "$v1name"
assert_eq "version 2 file name recorded" "valid_v2.pdf" "$v2name"

# --- FR-11: document detail shows BOTH versions and the return comment history ---
body=$(curl -s -c "$F2" -b "$F2" "$BASE/submissions/2")
if echo "$body" | grep -q "valid.pdf" && echo "$body" | grep -q "valid_v2.pdf"; then
  pass "document detail lists both file versions"
else
  fail "document detail does not list both versions"
fi
if echo "$body" | grep -qi "Please fix the signature page"; then pass "document detail shows prior reviewer comments"; else fail "document detail missing prior reviewer comments"; fi

# --- FR-21/FR-30: secretary notified + audit entry recorded for resubmission ---
SEC="$QA/rs_sec.jar"
rm -f "$SEC"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
notif=$(curl -s -c "$SEC" -b "$SEC" "$BASE/notifications")
if echo "$notif" | grep -qi "resubmitted"; then pass "secretary receives a resubmission notification"; else fail "secretary did NOT receive a resubmission notification"; fi

audit_row=$(DB "select action from audit_log where entity_type='submission' and entity_id=2 and action='resubmit';")
assert_eq "audit log recorded a 'resubmit' action for submission 2" "resubmit" "$audit_row"

# --- Resubmitting again while status=Submitted must be rejected (same guard as FR-7 test) ---
csrf=$(get_csrf "$F2" "/faculty/requirements")
body=$(curl -s -c "$F2" -b "$F2" -F "csrf_token=$csrf" -F "document=@$WQA/valid.pdf;type=application/pdf;filename=valid3.pdf" -L "$BASE/faculty/submissions/2/upload")
if echo "$body" | grep -qi "be uploaded to right now"; then pass "further upload while Submitted (post-resubmit) rejected"; else fail "further upload while Submitted (post-resubmit) NOT rejected"; fi
row=$(DB "select current_version from submissions where submission_id=2;")
assert_eq "version still 2 (no accidental 3rd version)" "2" "$row"

result_line
