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
# The Faculty sidebar USED to be deliberately unheaded ("too few items to need
# the Secretary's group headings"). The client compared the two menus and asked
# for one consistent pattern, so the expectation is now inverted: headings are
# required, in the same .sidenav-heading markup the Secretary uses.
if echo "$fac_dash_body" | grep -q 'sidenav-heading'; then
  pass "Faculty sidebar carries .sidenav-heading groups, matching the Secretary's pattern"
else
  fail "Faculty sidebar has no .sidenav-heading groups -- it should be grouped like the Secretary's menu"
fi
# ...but only ITS OWN groups. Review/Configure/Records head Secretary-only
# screens, so a Faculty menu showing them would be advertising pages the server
# will 403. Overview and Documents are the two the Faculty menu should have.
fac_headings=$(echo "$fac_dash_body" | grep -o '<p class="sidenav-heading">[^<]*</p>' | sed -E 's/.*>([^<]*)<.*/\1/' | tr '\n' ',' | sed 's/,$//')
assert_eq "Faculty sidebar headings are Overview + Documents only" "Overview,Documents" "$fac_headings"

# --- Secretary sidebar DOES contain the admin links (sanity: the assertion
# above isn't vacuously true because the check itself never fires) ---
sec_dash_body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/dashboard")
if echo "$sec_dash_body" | grep -q 'href="/admin/requirements"'; then
  pass "Secretary sidebar DOES contain /admin links (control check)"
else
  fail "Secretary sidebar unexpectedly missing /admin links -- control check failed, casts doubt on the Faculty-link assertion above"
fi

# --- Persistent top bar: unread indicator (FR-23) + logout (FR-4) ---
# The bar renders at every width for both roles. Two things it must never get
# wrong: the bell is an icon, so the unread count has to reach a screen reader
# through the accessible name; and logout must stay a POST + CSRF form, because
# a GET logout is forgeable from any third-party page.
check_topbar() {
  local body="$1" who="$2"

  local label
  label=$(echo "$body" | grep -o 'aria-label="Notifications[^"]*"' | head -1 | sed -E 's/aria-label="([^"]*)"/\1/')
  if echo "$label" | grep -qE '^Notifications \((no unread|[0-9]+ unread)\)$'; then
    pass "$who top bar: bell has a counted accessible name (\"$label\")"
  else
    fail "$who top bar: bell accessible name is \"$label\" -- expected \"Notifications (N unread)\" or \"(no unread)\""
  fi

  if echo "$body" | grep -q 'class="topbar-action notif-bell" href="/notifications"'; then
    pass "$who top bar: bell links to /notifications"
  else
    fail "$who top bar: no bell link to /notifications"
  fi

  local logout_forms logout_links
  logout_forms=$(echo "$body" | grep -c 'action="/logout"')
  logout_links=$(echo "$body" | grep -c 'href="/logout"')
  assert_eq "$who: exactly one logout control on the page" "1" "$logout_forms"
  assert_eq "$who: zero GET/link-style logout controls" "0" "$logout_links"

  if echo "$body" | grep -A2 'action="/logout"' | grep -q 'name="csrf_token"'; then
    pass "$who: logout form carries a csrf_token hidden input"
  else
    fail "$who: logout form has no csrf_token hidden input"
  fi

  # The sidebar's Notifications entry is kept on purpose alongside the bell --
  # it is navigation to a screen, the bell is a status indicator.
  if echo "$body" | grep -q 'class="sidenav-link notif-link'; then
    pass "$who: sidebar keeps its Notifications nav link alongside the bell"
  else
    fail "$who: sidebar Notifications nav link is missing"
  fi
}
check_topbar "$sec_dash_body" "Secretary"
check_topbar "$fac_dash_body" "Faculty"

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
