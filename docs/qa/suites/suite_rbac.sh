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
  # FR-38 status screens behind the Review sub-nav. All four statuses, not just
  # the three the sub-nav links to: /reviewer/status/submitted is a real route
  # (the status exists) and must be just as Secretary-only as the linked three.
  "/reviewer/status/approved"
  "/reviewer/status/pending"
  "/reviewer/status/revised"
  "/reviewer/status/submitted"
  # An unknown status is still a Secretary-only route: Faculty must get 403
  # (role first), not the 404 the Secretary gets, so the route cannot be used
  # to probe which status values exist.
  "/reviewer/status/nosuchstatus"
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
  # FR-37 search: reachable by both roles, never anonymously. WHAT each role
  # gets back is asserted separately below -- this is only the route check.
  "/search"
  "/search?q=a"
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


# --- FR-37 search scoping: a role boundary, not a filter ---
# The search route is shared, so the authorization question is not "may I reach
# it" but "what does it return". A Faculty user must never see another faculty
# member's records through it, including by editing the query string. faculty2
# is a second Faculty account whose data faculty1 must not be able to reach.
FAC2="$QA/r_fac2.jar"
rm -f "$FAC2"
login "$FAC2" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null

# 1. The Secretary-only result groups must not render for a Faculty user at
#    all: faculty names are not in a Faculty user's scope.
fac_search=$(curl -s -c "$FAC" -b "$FAC" "$BASE/search?q=a")
for group in 'Faculty (' 'Document types ('; do
  if echo "$fac_search" | grep -q "section-title\">$group"; then
    fail "Faculty search renders the Secretary-only group \"$group\""
  else
    pass "Faculty search does not render the Secretary-only group \"$group\""
  fi
done
sec_search=$(curl -s -c "$SEC" -b "$SEC" "$BASE/search?q=a")
if echo "$sec_search" | grep -q 'section-title">Faculty ('; then
  pass "Secretary search DOES render the Faculty group (control check)"
else
  fail "Secretary search is missing the Faculty group -- control check failed, casts doubt on the assertion above"
fi

# 2. Searching another faculty member's NAME returns nothing of theirs.
for term in Reyes Angelica faculty2; do
  hits=$(curl -s -c "$FAC" -b "$FAC" "$BASE/search?q=$term" | grep -oE '[0-9]+ results? for' | grep -oE '^[0-9]+')
  assert_eq "faculty1 searching \"$term\" (faculty2's identity) returns 0 results" "0" "$hits"
done

# 3. The rows a Faculty user DOES get back are only their own submissions.
#    Cross-checked against the other account: the two id sets must not overlap.
f1_ids=$(curl -s -c "$FAC" -b "$FAC" "$BASE/search?q=a" | grep -oE '/submissions/[0-9]+' | sort -u | tr '\n' ' ' | sed 's/ $//')
f2_ids=$(curl -s -c "$FAC2" -b "$FAC2" "$BASE/search?q=a" | grep -oE '/submissions/[0-9]+' | sort -u | tr '\n' ' ' | sed 's/ $//')
if [ -n "$f1_ids" ] && [ -n "$f2_ids" ]; then
  pass "both faculty accounts get non-empty search results (control check: the overlap test below is not vacuous)"
else
  fail "one of the faculty accounts got no search results -- the overlap test below would pass vacuously"
fi
overlap=$(comm -12 <(echo "$f1_ids" | tr ' ' '\n' | sort -u) <(echo "$f2_ids" | tr ' ' '\n' | sort -u) | grep -c .)
assert_eq "faculty1 and faculty2 search results share zero submission ids" "0" "$overlap"

# 4. Crafting a scope parameter onto the query string changes nothing. The
#    route takes no such parameter by design -- the faculty id is bound from
#    the session inside the SQL -- so these must all return the same rows as
#    the plain search above.
for qs in "q=a&user_id=3" "q=a&faculty_id=3" "q=a&faculty_name=Angelica" "q=a&role=Secretary" "q=a&isSecretary=1"; do
  crafted=$(curl -s -c "$FAC" -b "$FAC" "$BASE/search?$qs" | grep -oE '/submissions/[0-9]+' | sort -u | tr '\n' ' ' | sed 's/ $//')
  assert_eq "faculty1 /search?$qs returns only faculty1's own rows" "$f1_ids" "$crafted"
done

# 5. A document uploaded by one faculty member is invisible to the other, by
#    filename. (fchapter4.docx belongs to faculty1.)
f1_file=$(curl -s -c "$FAC" -b "$FAC" "$BASE/search?q=fchapter4" | grep -c 'fchapter4.docx')
f2_file=$(curl -s -c "$FAC2" -b "$FAC2" "$BASE/search?q=fchapter4" | grep -c 'fchapter4.docx')
if [ "$f1_file" -gt 0 ]; then
  pass "faculty1 finds their own uploaded file by name (control check)"
else
  fail "faculty1 cannot find their own uploaded file -- control check failed, casts doubt on the assertion below"
fi
assert_eq "faculty2 cannot find faculty1's uploaded file by name" "0" "$f2_file"

result_line
