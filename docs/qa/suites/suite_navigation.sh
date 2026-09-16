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
  "/reviewer/status/approved:Approved submissions (FR-38)"
  "/reviewer/status/pending:Pending submissions (FR-38)"
  "/reviewer/status/revised:Revised submissions (FR-38)"
  "/reviewer/status/submitted:Submitted submissions (FR-38)"
  "/search:Search, no term (FR-37)"
  "/search?q=a:Search results (FR-37)"
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
  "/search:Search, no term (FR-37)"
  "/search?q=a:Search results (FR-37)"
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

# --- Status sub-navigation under Review (FR-38) ---
# The client asked for Approved / Pending / Revised as a SUB-level of the
# Review group, not as three more top-level items, so the assertions are about
# the nesting as much as the links: a .sidenav-sub wrapper, .sidenav-sublink on
# each entry, and the wrapper sitting between "Review Queue" and "Faculty
# Compliance" -- which is what puts the queue (the Submitted screen) next to
# the other three, so the set reads as complete.
sec_subnav_body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/dashboard")
if echo "$sec_subnav_body" | grep -q 'class="sidenav-sub"'; then
  pass "Secretary sidebar has a .sidenav-sub wrapper (status sub-nav is a sub-level, not four peers)"
else
  fail "Secretary sidebar has no .sidenav-sub wrapper -- the status entries would read as top-level items"
fi
for st in approved pending revised; do
  if echo "$sec_subnav_body" | grep -qE '<a class="sidenav-link sidenav-sublink[^"]*" href="/reviewer/status/'"$st"'"'; then
    pass "Secretary sub-nav has a .sidenav-sublink entry for $st"
  else
    fail "Secretary sub-nav missing the .sidenav-sublink entry for $st"
  fi
done
# Deliberately THREE, not four: /reviewer/status/submitted renders (it is a
# real status) but has no sidebar entry, because Review Queue owns Submitted.
subnav_count=$(echo "$sec_subnav_body" | grep -o 'href="/reviewer/status/[a-z]*"' | sort -u | wc -l)
assert_eq "Secretary sub-nav has exactly 3 status entries (Submitted is owned by Review Queue)" "3" "$subnav_count"
if echo "$sec_subnav_body" | grep -q 'href="/reviewer/status/submitted"'; then
  fail "Secretary sub-nav links to /reviewer/status/submitted -- that would duplicate Review Queue"
else
  pass "Secretary sub-nav does NOT link to /reviewer/status/submitted (Review Queue owns it)"
fi
# Ordering: the sub-nav must sit between Review Queue and Faculty Compliance.
# Scoped to the sidebar <nav> first -- the dashboard BODY also links to
# /reviewer/queue and /reviewer/compliance (its shortcut cards), and those hits
# would otherwise land in this sequence and make the assertion meaningless.
nav_order=$(echo "$sec_subnav_body" | sed -n '/<nav class="sidenav"/,/<\/nav>/p' | grep -oE 'href="/reviewer/queue"|class="sidenav-sub"|href="/reviewer/compliance"' | tr '\n' ',' | sed 's/,$//')
assert_eq "sub-nav sits directly under Review Queue, above Faculty Compliance" 'href="/reviewer/queue",class="sidenav-sub",href="/reviewer/compliance"' "$nav_order"
# Faculty must never see it: these are Secretary-only screens and the server
# 403s them, so advertising them in the Faculty menu would be a broken link.
if echo "$fac_dash_body" | grep -q 'sidenav-sub'; then
  fail "Faculty sidebar contains the status sub-nav (Secretary-only screens)"
else
  pass "Faculty sidebar contains no status sub-nav"
fi

# --- The sub-nav highlights the current entry, and only that entry ---
for st in approved pending revised; do
  body_st=$(curl -s -c "$SEC" -b "$SEC" "$BASE/reviewer/status/$st")
  if echo "$body_st" | grep -oE '<a class="sidenav-link sidenav-sublink[^"]*" href="/reviewer/status/'"$st"'"[^>]*>' | grep -q 'is-current'; then
    pass "/reviewer/status/$st highlights its own sub-nav entry"
  else
    fail "/reviewer/status/$st does NOT highlight its own sub-nav entry"
  fi
  if echo "$body_st" | grep -oE '<a class="sidenav-link[^"]*" href="/reviewer/queue"[^>]*>' | grep -q 'is-current'; then
    fail "/reviewer/status/$st incorrectly ALSO highlights 'Review Queue'"
  else
    pass "/reviewer/status/$st does not incorrectly highlight 'Review Queue'"
  fi
done

# --- Top-bar search field (FR-37) ---
# The field is in the top bar for BOTH roles. Two things it must not get wrong:
# it stays a GET (search is read-only, so a POST would be wrong and a CSRF
# token noise), and the role must not be expressed as a form field -- scoping
# is decided server-side, so there must be nothing in the markup to edit.
check_search_field() {
  local body="$1" who="$2" expected_placeholder="$3"

  if echo "$body" | grep -q '<form class="topbar-search" method="get" action="/search" role="search">'; then
    pass "$who top bar: search is a GET form to /search"
  else
    fail "$who top bar: no GET search form to /search in the top bar"
  fi

  local placeholder
  placeholder=$(echo "$body" | grep -o 'placeholder="[^"]*"' | head -1 | sed -E 's/placeholder="([^"]*)"/\1/')
  assert_eq "$who top bar: search placeholder names that role's scope" "$expected_placeholder" "$placeholder"

  # The only field the form may submit is the term itself.
  local fields
  fields=$(echo "$body" | sed -n '/class="topbar-search"/,/<\/form>/p' | grep -o 'name="[^"]*"' | sed -E 's/name="([^"]*)"/\1/' | sort -u | tr '\n' ',' | sed 's/,$//')
  assert_eq "$who top bar: search form submits only q (no role/scope field to forge)" "q" "$fields"

  if echo "$body" | sed -n '/class="topbar-search"/,/<\/form>/p' | grep -q 'csrf_token'; then
    fail "$who top bar: search form carries a csrf_token -- it is a read-only GET and should not"
  else
    pass "$who top bar: search form carries no csrf_token (read-only GET)"
  fi

  # The input's only visible text is a placeholder, which is not an accessible
  # name, so the .sr-only <label> is what a screen reader has to announce.
  if echo "$body" | grep -q '<label class="sr-only" for="topbar-search-q">'; then
    pass "$who top bar: search input has a real (visually hidden) label"
  else
    fail "$who top bar: search input has no <label> -- a placeholder is not an accessible name"
  fi
}
check_search_field "$sec_dash_body" "Secretary" "Search faculty, requirements, types"
check_search_field "$fac_dash_body" "Faculty" "Search my requirements and documents"

# --- Search term: echoed back escaped, and only on the results screen ---
xss_body=$(curl -s -c "$SEC" -b "$SEC" --get --data-urlencode 'q=<script>alert(1)</script>' "$BASE/search")
if echo "$xss_body" | grep -q '&lt;script&gt;alert(1)&lt;/script&gt;'; then
  pass "search echoes the term back HTML-escaped"
else
  fail "search does not echo the term back escaped"
fi
script_tags=$(echo "$xss_body" | grep -c '<script')
assert_eq "search results page has exactly one <script> tag (the app.js include)" "1" "$script_tags"
stray_q=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/dashboard?q=leaky" | grep -c 'value="leaky"')
assert_eq "a stray ?q= on another screen is not echoed into the search box" "0" "$stray_q"

result_line
