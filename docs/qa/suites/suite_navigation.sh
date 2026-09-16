#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"

echo "=== Navigation / sidebar regression + CSP inline-attribute suite ==="

SEC="$QA/nv_sec.jar"
FAC="$QA/nv_fac.jar"
rm -f "$SEC" "$FAC"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
login "$FAC" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null

# --- Static source scan (already confirmed 0 via grep -rn over app/Views,
# repeated here so it's part of the recorded suite run) ---
PROJECT="/c/Users/Admin/OneDrive/Desktop/Jhon Clarence Rulona/Clients/CCIS-Document_Management_System/ccis-dms"
style_hits=$(grep -rniE 'style="' "$PROJECT/app/Views" 2>/dev/null | wc -l)
onattr_hits=$(grep -rniE 'on(click|change|submit|load|error|mouseover|mouseout|input|focus|blur|keyup|keydown|dblclick)[[:space:]]*=' "$PROJECT/app/Views" 2>/dev/null | wc -l)
assert_eq "zero inline style= attributes in app/Views source" "0" "$style_hits"
assert_eq "zero inline on*= event handler attributes in app/Views source" "0" "$onattr_hits"
script_tag_count=$(grep -rn '<script' "$PROJECT/app/Views" | grep -cv 'src=')
assert_eq "every <script> tag in app/Views is external (src=), none inline" "0" "$script_tag_count"

# --- Every screen renders (200) for its role, with no inline style=/on*= in
# the ACTUAL rendered response (catches anything built dynamically that a
# static source grep could miss). ---
check_page() {
  local jar="$1" path="$2" label="$3"
  local body code
  body=$(curl -s -c "$jar" -b "$jar" -w "\nHTTPCODE:%{http_code}" "$BASE$path")
  code=$(echo "$body" | grep -o 'HTTPCODE:[0-9]*' | cut -d: -f2)
  body=$(echo "$body" | sed '$d')
  if [ "$code" = "200" ]; then pass "$label ($path) renders -> 200"; else fail "$label ($path) -> $code (expected 200)"; fi
  if echo "$body" | grep -qiE 'style="'; then fail "$label ($path) response contains an inline style= attribute"; else pass "$label ($path) response has zero inline style="; fi
  if echo "$body" | grep -qiE 'on(click|change|submit|load|error|mouseover|mouseout|input|focus|blur|keyup|keydown|dblclick)[[:space:]]*='; then
    fail "$label ($path) response contains an inline on*= event handler"
  else
    pass "$label ($path) response has zero inline on*= handlers"
  fi
  printf '%s' "$body"
}

SEC_PAGES=(
  "/admin/dashboard:Secretary dashboard"
  "/admin/monitoring:Monitoring board"
  "/admin/requirements:Requirements list"
  "/admin/requirements/new:Requirement form"
  "/admin/document-types:Document types list"
  "/admin/document-types/new:Document type form"
  "/admin/periods:Periods list"
  "/admin/periods/new:Period form"
  "/admin/users:Users list"
  "/admin/users/new:User form"
  "/admin/reports:Reports"
  "/admin/audit-log:Audit log"
  "/reviewer/queue:Review queue"
  "/reviewer/compliance:Compliance view"
  "/archive:Archive index"
  "/notifications:Notifications"
  "/profile:Profile"
)
for entry in "${SEC_PAGES[@]}"; do
  path="${entry%%:*}"; label="${entry#*:}"
  check_page "$SEC" "$path" "$label" > /dev/null
done

FAC_PAGES=(
  "/faculty/dashboard:Faculty dashboard"
  "/faculty/requirements:My Requirements"
  "/archive:Archive index"
  "/notifications:Notifications"
  "/profile:Profile"
)
for entry in "${FAC_PAGES[@]}"; do
  path="${entry%%:*}"; label="${entry#*:}"
  check_page "$FAC" "$path" "$label" > /dev/null
done

# --- Faculty NEVER sees admin/reviewer sidebar links ---
fac_dash_body=$(curl -s -c "$FAC" -b "$FAC" "$BASE/faculty/dashboard")
if echo "$fac_dash_body" | grep -qE 'href="/admin/|href="/reviewer/'; then
  fail "Faculty sidebar contains an /admin or /reviewer link (should never be visible to Faculty)"
else
  pass "Faculty sidebar contains no /admin or /reviewer links"
fi
if echo "$fac_dash_body" | grep -q 'sidenav-heading'; then
  fail "Faculty sidebar shows Secretary-style section headings (Overview/Review/Configure/Records) -- should be the short unheaded Faculty menu"
else
  pass "Faculty sidebar correctly omits the Secretary's section headings"
fi

# --- Secretary sidebar DOES contain the admin links (sanity: the assertion
# above isn't vacuously true because the check itself never fires) ---
sec_dash_body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/dashboard")
if echo "$sec_dash_body" | grep -q 'href="/admin/requirements"'; then
  pass "Secretary sidebar DOES contain /admin links (control check)"
else
  fail "Secretary sidebar unexpectedly missing /admin links -- control check failed, casts doubt on the Faculty-link assertion above"
fi

# --- Active-page highlight: the specific case called out in the brief ---
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/requirements/new")
if echo "$body" | grep -oE '<a class="sidenav-link[^"]*" href="/admin/requirements"[^>]*>[^<]*</a>' | grep -q 'is-current'; then
  pass "/admin/requirements/new highlights 'Requirements' in the sidebar (is-current class present)"
else
  fail "/admin/requirements/new does NOT highlight 'Requirements' in the sidebar"
fi
# and it must NOT ALSO highlight a sibling item (e.g. Dashboard) at the same time
if echo "$body" | grep -oE '<a class="sidenav-link[^"]*" href="/admin/dashboard"[^>]*>[^<]*</a>' | grep -q 'is-current'; then
  fail "/admin/requirements/new incorrectly ALSO highlights 'Dashboard'"
else
  pass "/admin/requirements/new does not incorrectly highlight 'Dashboard'"
fi

# A second case: /admin/document-types/new highlights 'Document Types' only
body2=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/document-types/new")
if echo "$body2" | grep -oE '<a class="sidenav-link[^"]*" href="/admin/document-types"[^>]*>[^<]*</a>' | grep -q 'is-current'; then
  pass "/admin/document-types/new highlights 'Document Types' in the sidebar"
else
  fail "/admin/document-types/new does NOT highlight 'Document Types'"
fi

# Faculty checklist page highlights 'My Requirements'
body3=$(curl -s -c "$FAC" -b "$FAC" "$BASE/faculty/requirements")
if echo "$body3" | grep -oE '<a class="sidenav-link[^"]*" href="/faculty/requirements"[^>]*>[^<]*</a>' | grep -q 'is-current'; then
  pass "/faculty/requirements highlights 'My Requirements' in the sidebar"
else
  fail "/faculty/requirements does NOT highlight 'My Requirements'"
fi

# --- Response security headers present on every response (defense in depth
# alongside the inline-attribute checks -- these are what make a stray
# inline style/script actually get blocked by the browser rather than just
# being absent by convention) ---
headers=$(curl -s -D - -o /dev/null -c "$SEC" -b "$SEC" "$BASE/admin/dashboard")
if echo "$headers" | grep -qi "^Content-Security-Policy:.*default-src 'self'"; then pass "CSP header present with default-src 'self'"; else fail "CSP header missing or does not restrict default-src to 'self'"; fi
if echo "$headers" | grep -qi "^X-Content-Type-Options: nosniff"; then pass "X-Content-Type-Options: nosniff present"; else fail "X-Content-Type-Options header missing"; fi
if echo "$headers" | grep -qi "^X-Frame-Options: DENY"; then pass "X-Frame-Options: DENY present"; else fail "X-Frame-Options header missing"; fi

result_line
