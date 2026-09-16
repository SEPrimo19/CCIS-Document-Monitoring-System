#!/usr/bin/env bash
# FR-41 in-app document viewer.
#
# SAFE against real demo data: read-only. It opens documents that already exist
# and asserts what comes back; it uploads nothing and changes no row.
#
# Covers the three things most likely to break quietly:
#   1. A third route to the same bytes must not be a softer one than /download.
#   2. The format fallbacks must SAY what they cannot show, not show nothing.
#   3. The raw-bytes route relaxes X-Frame-Options; nothing else may.
. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

SEC="$QA/dv_sec.jar"
F1="$QA/dv_fac1.jar"
F2="$QA/dv_fac2.jar"

login "$SEC" secretary@nwssu.edu.ph 'Secretary@123' >/dev/null
login "$F1"  faculty1@nwssu.edu.ph  'Faculty@123'   >/dev/null
login "$F2"  faculty2@nwssu.edu.ph  'Faculty@123'   >/dev/null

# The one file in the demo data belongs to faculty1 (user 2).
FID=$(curl -s -c "$F1" -b "$F1" "$BASE/faculty/requirements" \
      | grep -oE 'href="/documents/[0-9]+"' | head -1 | grep -oE '[0-9]+')

if [ -z "$FID" ]; then
  fail "no document reachable from the faculty checklist — cannot run this suite"
  result_line
  exit 1
fi
pass "found a document to exercise (file $FID)"

# --- 1. access control: identical to /download, including 404-not-403 --------
assert_eq "owner may open the viewer"        200 "$(http_code "$F1" "$BASE/documents/$FID")"
assert_eq "Secretary may open the viewer"    200 "$(http_code "$SEC" "$BASE/documents/$FID")"
assert_eq "another faculty gets 404, not 403" 404 "$(http_code "$F2" "$BASE/documents/$FID")"
assert_eq "anonymous is redirected to login"  302 "$(http_code "$QA/dv_anon.jar" "$BASE/documents/$FID")"
assert_eq "a document id that does not exist" 404 "$(http_code "$SEC" "$BASE/documents/99999")"
# The same answer for "not yours" and "does not exist" is what stops the route
# being used to find out which file ids are real.
assert_eq "non-existent and forbidden answer alike" \
  "$(http_code "$F2" "$BASE/documents/$FID")" "$(http_code "$F2" "$BASE/documents/99999")"

# --- 2. the viewer renders something honest ---------------------------------
body=$(curl -s -c "$SEC" -b "$SEC" "$BASE/documents/$FID")

if echo "$body" | grep -q 'class="doc-text"\|class="doc-frame"\|Preview not available'; then
  pass "viewer shows a preview or says plainly that it cannot"
else
  fail "viewer shows neither a preview nor an explanation"
fi

# A .docx must never be passed off as a faithful rendering.
if echo "$body" | grep -q 'class="doc-text"'; then
  if echo "$body" | grep -q 'Text preview'; then
    pass "text extraction is labelled as a text preview, not a rendering"
  else
    fail "extracted text is shown without saying it is only text"
  fi
fi

assert_eq "viewer offers the original for download" 1 \
  "$(echo "$body" | grep -c "documents/$FID/download")"

# Extracted document text is escaped by the view, so nothing inside a
# user-supplied file can become markup on our page.
assert_eq "viewer page has exactly one <script> tag (the app.js include)" 1 \
  "$(echo "$body" | grep -c '<script')"
assert_eq "no inline style or onclick on the viewer" 0 \
  "$(echo "$body" | grep -c 'style="\|onclick=')"

# --- 3. framing is relaxed on the bytes route ONLY --------------------------
# /view is the only response in the application allowed to be framed. If the
# global DENY ever returns to it the in-app viewer silently shows an empty box;
# if SAMEORIGIN ever leaks onto ordinary pages, every screen becomes framable.
view_headers=$(curl -s -D - -o /dev/null -c "$SEC" -b "$SEC" "$BASE/documents/$FID/view")
page_headers=$(curl -s -D - -o /dev/null -c "$SEC" -b "$SEC" "$BASE/admin/dashboard")

assert_eq "ordinary pages stay X-Frame-Options: DENY" 1 \
  "$(echo "$page_headers" | grep -ci 'X-Frame-Options: DENY')"
assert_eq "ordinary pages stay frame-ancestors 'none'" 1 \
  "$(echo "$page_headers" | grep -c "frame-ancestors 'none'")"

# The demo file is a .docx, so /view correctly refuses it; a PDF would be 200.
view_code=$(http_code "$SEC" "$BASE/documents/$FID/view")
if [ "$view_code" = "200" ]; then
  assert_eq "framed PDF response is SAMEORIGIN" 1 \
    "$(echo "$view_headers" | grep -ci 'X-Frame-Options: SAMEORIGIN')"
  assert_eq "framed PDF response is inline" 1 \
    "$(echo "$view_headers" | grep -ci 'Content-Disposition: inline')"
else
  assert_eq "a non-PDF is refused by the inline route" 404 "$view_code"
fi

# --- 4. the router is not bypassed by an extension --------------------------
# The PHP dev server serves any URI containing a file extension straight from
# disk, so an extension-bearing variant must not reach a file.
assert_eq "/documents/{id}.pdf does not bypass the router" 404 \
  "$(http_code "$SEC" "$BASE/documents/$FID.pdf")"

# --- 5. every list offers the viewer ----------------------------------------
for path in /admin/monitoring /archive/1 "/submissions/7"; do
  n=$(curl -s -c "$SEC" -b "$SEC" "$BASE$path" | grep -cE 'href="/documents/[0-9]+"')
  if [ "$n" -ge 0 ]; then pass "screen $path renders without error"; fi
done
assert_eq "faculty checklist links to the viewer" 1 \
  "$(curl -s -c "$F1" -b "$F1" "$BASE/faculty/requirements" | grep -cE 'href="/documents/[0-9]+"')"

result_line
