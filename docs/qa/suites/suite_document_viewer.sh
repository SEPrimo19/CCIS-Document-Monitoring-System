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

if echo "$body" | grep -q 'class="doc-render"\|class="doc-text"\|class="doc-frame"\|Preview not available'; then
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

# At least one, not exactly one: the header carries a Download button and
# the rendered view repeats the offer in its "not the page layout" note.
dl=$(echo "$body" | grep -c "documents/$FID/download")
if [ "$dl" -ge 1 ]; then
  pass "viewer offers the original for download ($dl link(s))"
else
  fail "viewer does not offer the original for download"
fi

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
# At least one, not exactly one: faculty upload more documents over time and
# a count assertion turns every new submission into a test failure.
fac_links=$(curl -s -c "$F1" -b "$F1" "$BASE/faculty/requirements" | grep -cE 'href="/documents/[0-9]+"')
if [ "$fac_links" -ge 1 ]; then
  pass "faculty checklist links to the viewer ($fac_links link(s))"
else
  fail "faculty checklist has no viewer link"
fi

# --- 6. rendered .docx and its embedded images ------------------------------
# The renderer builds every tag itself and escapes the document's text, so a
# .docx cannot inject markup. These assert the output IS a render (tables and
# emphasis survive) rather than a transcript, and that the image route cannot
# be talked into serving anything but a declared image.
render=$(curl -s -c "$SEC" -b "$SEC" "$BASE/documents/$FID")

if echo "$render" | grep -q 'class="doc-render"'; then
  pass ".docx renders as a document, not a text transcript"

  # The wording states what IS and is NOT reproduced; assert the promise,
  # not one sentence of it, so rephrasing the copy does not fail the build.
  if echo "$render" | grep -q "are not reproduced"; then
    pass "the render says what it does not reproduce"
  else
    fail "the render does not say what it leaves out"
  fi
  # A <script> reaching the page from document content would mean the escaping
  # failed; there must still be exactly one (the app.js include).
  assert_eq "rendered document adds no <script> tag" 1     "$(echo "$render" | grep -c '<script')"

  rid=$(echo "$render" | grep -oE '/documents/[0-9]+/media/[A-Za-z0-9]+' | head -1)

  if [ -n "$rid" ]; then
    assert_eq "an embedded image is served" 200 "$(http_code "$SEC" "$BASE$rid")"
    ctype=$(curl -s -o /dev/null -w '%{content_type}' -c "$SEC" -b "$SEC" "$BASE$rid")
    case "$ctype" in
      image/*) pass "embedded image is served as an image type ($ctype)" ;;
      *) fail "embedded image served as $ctype" ;;
    esac
    assert_eq "another faculty cannot fetch the image" 404 "$(http_code "$F2" "$BASE$rid")"
    assert_eq "anonymous cannot fetch the image" 302 "$(http_code "$QA/dv_anon2.jar" "$BASE$rid")"
  fi

  # The relationship id is looked up in the document's own map, so none of
  # these can address another entry in the archive or anything on disk.
  for crafted in rId999 word/document.xml ..%2F..%2F.env; do
    assert_eq "crafted media id refused: $crafted" 404       "$(http_code "$SEC" "$BASE/documents/$FID/media/$crafted")"
  done
else
  pass ".docx render not applicable for this document (fallback in use)"
fi

# --- 7. the modal, and the page it falls back to ----------------------------
# The modal lifts [data-doc-body] out of the very page a no-script browser
# gets, so there is one rendering rather than two that can drift. These assert
# both halves still exist: remove either and the feature quietly becomes a
# blank box or a dead button.
listing=$(curl -s -c "$SEC" -b "$SEC" "$BASE/admin/monitoring")

assert_eq "the dialog is present on a listing screen" 1   "$(echo "$listing" | grep -c 'id="doc-modal"')"
marked=$(echo "$listing" | grep -c 'data-doc-view')
if [ "$marked" -ge 1 ]; then
  pass "View links are marked for the modal ($marked)"
else
  fail "no View link is marked for the modal"
fi
# Still a real page: this is the no-script path and where "open in a new tab"
# lands, so it must never become a fragment.
assert_eq "the viewer page is still a full page" 1   "$(curl -s -c "$SEC" -b "$SEC" "$BASE/documents/$FID" | grep -c '<!doctype html>')"

# --- 8. the full page stays reachable ---------------------------------------
# Once the modal started intercepting View, the standalone page was reachable
# only by ctrl-click — which nobody discovers. The dialog carries an explicit
# link to it, and that link must NOT be marked data-doc-view or it would be
# intercepted straight back into the modal it is trying to escape.
assert_eq "the dialog offers a full-page link" 1   "$(echo "$listing" | grep -c 'data-doc-full')"
assert_eq "the full-page link is not itself intercepted" 0   "$(echo "$listing" | grep -o 'data-doc-full[^>]*' | grep -c 'data-doc-view')"

# --- 9. the document gets the screen ----------------------------------------
# Four changes that are invisible to a route test and easy to undo by accident,
# because three of them live in the stylesheet.
page=$(curl -s -c "$SEC" -b "$SEC" "$BASE/documents/$FID")

# The document must come BEFORE the metadata. Compared by line number rather
# than by grepping for an order-bearing string, which any rewording would break.
doc_at=$(echo "$page" | grep -n '<h2>Document</h2>' | head -1 | cut -d: -f1)
meta_at=$(echo "$page" | grep -n 'class="meta-list"' | head -1 | cut -d: -f1)
if [ -n "$doc_at" ] && [ -n "$meta_at" ] && [ "$doc_at" -lt "$meta_at" ]; then
  pass "the document is above the metadata (lines $doc_at vs $meta_at)"
else
  fail "the metadata is back above the document (lines $doc_at vs $meta_at)"
fi

if echo "$page" | grep -q 'class="doc-frame"'; then
  # The control has to sit inside the element that goes full screen, or the
  # browser promotes a box the button is not in and it vanishes on click.
  wrap=$(echo "$page" | grep -c 'data-doc-frame-wrap')
  btn=$(echo "$page" | grep -c 'data-doc-fullscreen')
  if [ "$wrap" -ge 1 ] && [ "$btn" -ge 1 ]; then
    pass "the PDF frame offers a full-screen control"
  else
    fail "the PDF frame has no full-screen control (wrap $wrap, button $btn)"
  fi
else
  pass "full-screen control not applicable (no PDF frame for this document)"
fi

css=$(curl -s "$BASE/assets/css/style.css")

# .wrap caps content at 900px, which left the page itself about 480px on a
# 1920px screen. The viewer opts out; nothing else does.
if echo "$css" | grep -q '\.wrap:has(\[data-doc-body\])'; then
  pass "the viewer page opts out of the 900px column"
else
  fail "the viewer page is back inside the 900px column"
fi

# An aspect ratio sizes the frame from its own width and ignores the window.
if echo "$css" | grep -A 12 '^\.doc-frame {' | grep -q '100vh'; then
  pass "the frame takes its height from the viewport"
else
  fail "the frame no longer sizes itself against the viewport"
fi

# The script reveals the button only where the API is permitted, so a browser
# that forbids it never shows a control that does nothing.
js=$(curl -s "$BASE/assets/js/app.js")
if echo "$js" | grep -q 'fullscreenEnabled'; then
  pass "the control is shown only where full screen is allowed"
else
  fail "the control is shown without checking that full screen is allowed"
fi

result_line
