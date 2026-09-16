#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"

echo "=== FR-3 RBAC route enumeration -- gap fill (routes missed by suite_rbac.sh) ==="

SEC="$QA/rx_sec.jar"
FAC="$QA/rx_fac.jar"
ANON="$QA/rx_anon.jar"
rm -f "$SEC" "$FAC" "$ANON"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
login "$FAC" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null

# /admin/reports/export -- Secretary-only GET, missed by the main RBAC suite.
code=$(http_code "$FAC" "$BASE/admin/reports/export")
assert_eq "Faculty GET /admin/reports/export -> 403" "403" "$code"
code=$(http_code "$ANON" "$BASE/admin/reports/export")
assert_eq "Anonymous GET /admin/reports/export -> 302 (login redirect)" "302" "$code"
code=$(http_code "$SEC" "$BASE/admin/reports/export?report=status&period_id=1")
assert_eq "Secretary GET /admin/reports/export (valid params) -> 200" "200" "$code"

# Shared-auth (any authenticated role) routes not in the main RBAC route lists:
# /documents/{id}/download and /submissions/{id} require login (anon -> redirect),
# and are open to both roles at the Guard level (ownership is enforced separately,
# tested by the IDOR checks in suite_faculty_flow.sh).
code=$(http_code "$ANON" "$BASE/documents/1/download")
assert_eq "Anonymous GET /documents/1/download -> 302 (login redirect)" "302" "$code"
code=$(http_code "$ANON" "$BASE/submissions/1")
assert_eq "Anonymous GET /submissions/1 -> 302 (login redirect)" "302" "$code"

# /notifications/{id}/read and /notifications/read-all: any authenticated role,
# anon must be redirected, not merely refused.
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$ANON" -b "$ANON" -X POST "$BASE/notifications/1/read")
assert_eq "Anonymous POST /notifications/1/read -> 302 (login redirect)" "302" "$code"
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$ANON" -b "$ANON" -X POST "$BASE/notifications/read-all")
assert_eq "Anonymous POST /notifications/read-all -> 302 (login redirect)" "302" "$code"

# Both roles may hit /notifications/{id}/read and /notifications/read-all
# (Guard::requireAuth only) -- confirm neither is blocked with 403 (CSRF-less
# POST here just needs to not be role-blocked; correctness of the CSRF gate
# itself is exercised elsewhere).
csrf=$(get_csrf "$FAC" "/notifications")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$FAC" -b "$FAC" --data-urlencode "csrf_token=$csrf" -X POST "$BASE/notifications/read-all")
if [ "$code" = "403" ]; then fail "Faculty POST /notifications/read-all -> 403 (should be allowed)"; else pass "Faculty POST /notifications/read-all -> $code (allowed, not 403)"; fi
csrf=$(get_csrf "$SEC" "/notifications")
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" --data-urlencode "csrf_token=$csrf" -X POST "$BASE/notifications/read-all")
if [ "$code" = "403" ]; then fail "Secretary POST /notifications/read-all -> 403 (should be allowed)"; else pass "Secretary POST /notifications/read-all -> $code (allowed, not 403)"; fi

result_line
