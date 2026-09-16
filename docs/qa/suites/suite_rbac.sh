#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"

echo "=== FR-3 RBAC route enumeration ==="

SEC="$QA/r_sec.jar"
FAC="$QA/r_fac.jar"
ANON="$QA/r_anon.jar"
rm -f "$SEC" "$FAC" "$ANON"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
login "$FAC" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null

# GET routes that require Secretary role. Using ids=1 for {id} placeholders (existence
# doesn't matter for a role check that happens before lookup).
SECRETARY_GET_ROUTES=(
  "/admin/dashboard"
  "/admin/document-types"
  "/admin/document-types/new"
  "/admin/document-types/1/edit"
  "/admin/users"
  "/admin/users/new"
  "/admin/users/1/edit"
  "/admin/periods"
  "/admin/periods/new"
  "/admin/periods/1/edit"
  "/admin/requirements"
  "/admin/requirements/new"
  "/admin/monitoring"
  "/admin/audit-log"
  "/admin/reports"
  "/reviewer/queue"
  "/reviewer/submissions/1/review"
  "/reviewer/compliance"
)

for route in "${SECRETARY_GET_ROUTES[@]}"; do
  code=$(http_code "$FAC" "$BASE$route")
  assert_eq "Faculty GET $route -> 403" "403" "$code"
  code=$(http_code "$ANON" "$BASE$route")
  assert_eq "Anonymous GET $route -> 302 (login redirect)" "302" "$code"
  code=$(http_code "$SEC" "$BASE$route")
  # Secretary should get 200 (or 404 for a placeholder id that doesn't exist e.g. edit pages) -- never 403
  if [ "$code" = "403" ]; then fail "Secretary GET $route -> 403 (should be allowed)"; else pass "Secretary GET $route -> $code (allowed, not 403)"; fi
done

# Faculty-only GET routes
FACULTY_GET_ROUTES=(
  "/faculty/dashboard"
  "/faculty/requirements"
)
for route in "${FACULTY_GET_ROUTES[@]}"; do
  code=$(http_code "$SEC" "$BASE$route")
  assert_eq "Secretary GET $route -> 403" "403" "$code"
  code=$(http_code "$ANON" "$BASE$route")
  assert_eq "Anonymous GET $route -> 302 (login redirect)" "302" "$code"
  code=$(http_code "$FAC" "$BASE$route")
  if [ "$code" = "403" ]; then fail "Faculty GET $route -> 403 (should be allowed)"; else pass "Faculty GET $route -> $code (allowed, not 403)"; fi
done

# Shared-auth routes (any authenticated role, but never anonymous)
SHARED_GET_ROUTES=(
  "/dashboard"
  "/profile"
  "/notifications"
  "/archive"
  "/archive/1"
)
for route in "${SHARED_GET_ROUTES[@]}"; do
  code=$(http_code "$ANON" "$BASE$route")
  assert_eq "Anonymous GET $route -> 302 (login redirect)" "302" "$code"
  code=$(http_code "$FAC" "$BASE$route")
  if [ "$code" = "403" ]; then fail "Faculty GET $route -> 403 (should be allowed)"; else pass "Faculty GET $route -> $code (allowed)"; fi
  code=$(http_code "$SEC" "$BASE$route")
  if [ "$code" = "403" ]; then fail "Secretary GET $route -> 403 (should be allowed)"; else pass "Secretary GET $route -> $code (allowed)"; fi
done

# POST routes requiring Secretary role -- verify Faculty gets 403 and anon gets 302.
# Use bogus CSRF; role check happens before/regardless per Guard::requireRole ordering
# (Guard is called first in every handler we read), so 403 should occur even with a bad token.
SECRETARY_POST_ROUTES=(
  "/admin/document-types"
  "/admin/document-types/1"
  "/admin/document-types/1/deactivate"
  "/admin/document-types/1/activate"
  "/admin/users"
  "/admin/users/1"
  "/admin/users/1/deactivate"
  "/admin/users/1/activate"
  "/admin/periods"
  "/admin/periods/1"
  "/admin/periods/1/activate"
  "/admin/periods/1/deactivate"
  "/admin/requirements"
  "/reviewer/submissions/1/review"
)
for route in "${SECRETARY_POST_ROUTES[@]}"; do
  code=$(curl -s -o /dev/null -w "%{http_code}" -c "$FAC" -b "$FAC" -X POST "$BASE$route")
  assert_eq "Faculty POST $route -> 403" "403" "$code"
  code=$(curl -s -o /dev/null -w "%{http_code}" -c "$ANON" -b "$ANON" -X POST "$BASE$route")
  assert_eq "Anonymous POST $route -> 302 (login redirect)" "302" "$code"
done

# Faculty-only POST route
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$SEC" -b "$SEC" -X POST "$BASE/faculty/submissions/1/upload")
assert_eq "Secretary POST /faculty/submissions/1/upload -> 403" "403" "$code"
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$ANON" -b "$ANON" -X POST "$BASE/faculty/submissions/1/upload")
assert_eq "Anonymous POST /faculty/submissions/1/upload -> 302" "302" "$code"

result_line
