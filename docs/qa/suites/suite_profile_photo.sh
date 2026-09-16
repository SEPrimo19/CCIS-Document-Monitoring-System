#!/usr/bin/env bash
# FR-40 profile-photo acceptance test (26 checks).
# SAFE against real demo data: the only thing it writes is faculty1's own
# avatar, which it removes again at the end. Run make_avatar_fixtures.sh first.
BASE="http://localhost:8000"
FX=$(cygpath -w "$(dirname "$0")/avatar_fx")
# Fixtures are generated, not committed: bash make_avatar_fixtures.sh
STORE="/c/Users/Admin/OneDrive/Desktop/Jhon Clarence Rulona/Clients/CCIS-Document_Management_System/ccis-dms/storage/avatars"
J=/tmp/av_f1.jar
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "FAIL: $1"; }
ck() { if [ "$2" = "$3" ]; then pass "$1 (got $3)"; else fail "$1 (expected $2, got $3)"; fi; }

login() {
  rm -f "$1"
  local c
  c=$(curl -s -c "$1" -b "$1" "$BASE/login" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed -E 's/.*value="([^"]*)".*/\1/')
  curl -s -o /dev/null -c "$1" -b "$1" --data-urlencode "email=$2" --data-urlencode "password=$3" \
       --data-urlencode "csrf_token=$c" "$BASE/login"
}
csrf() { curl -s -c "$J" -b "$J" "$BASE/profile" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed -E 's/.*value="([^"]*)".*/\1/'; }
count_files() { ls -1 "$STORE" 2>/dev/null | grep -v '^\.gitignore$' | wc -l | tr -d ' '; }

# upload <file> -> prints the flash message
upload() {
  local c; c=$(csrf)
  curl -s -c "$J" -b "$J" -F "csrf_token=$c" -F "photo=@$FX\\$1" -L "$BASE/profile/photo" \
    | grep -o 'class="alert[^"]*"[^>]*>[^<]*' | head -1 | sed -E 's/.*>[[:space:]]*//'
}

login "$J" faculty1@nwssu.edu.ph 'Faculty@123' >/dev/null
echo "--- baseline ---"
BEFORE=$(count_files)
echo "files in storage/avatars: $BEFORE"

echo
echo "--- A. rejections (nothing should be written) ---"
for f in evil.svg shell.png notes.jpg empty.png toobig_px.png toosmall.png realjpeg_named_png.png; do
  msg=$(upload "$f")
  n=$(count_files)
  if [ "$n" = "$BEFORE" ]; then pass "$f rejected, no file written  [$msg]"; else fail "$f WROTE A FILE ($n vs $BEFORE)  [$msg]"; fi
done

echo
echo "--- B. accept a real JPEG ---"
msg=$(upload good.jpg); echo "  flash: $msg"
ck "one file now stored" $((BEFORE+1)) "$(count_files)"
AV=$(curl -s -b "$J" "$BASE/profile" | grep -o '/avatars/[0-9]*' | head -1)
ck "profile renders avatar url" "/avatars/2" "$AV"
ck "sidebar uses <img>" 1 "$(curl -s -b "$J" "$BASE/faculty/dashboard" | grep -c 'avatar-img')"
ck "serves 200" 200 "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$BASE/avatars/2")"
ck "content-type from bytes" "image/jpeg" "$(curl -s -o /dev/null -w '%{content_type}' -b "$J" "$BASE/avatars/2")"

echo
echo "--- C. replace must unlink the old file ---"
msg=$(upload good.png); echo "  flash: $msg"
ck "still exactly one file (old unlinked)" $((BEFORE+1)) "$(count_files)"
ck "content-type now png" "image/png" "$(curl -s -o /dev/null -w '%{content_type}' -b "$J" "$BASE/avatars/2")"

echo
echo "--- D. access control ---"
ck "anonymous cannot fetch" 302 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/avatars/2")"
login /tmp/av_sec.jar secretary@nwssu.edu.ph 'Secretary@123' >/dev/null
ck "signed-in other user may fetch (by design)" 200 "$(curl -s -o /dev/null -w '%{http_code}' -b /tmp/av_sec.jar "$BASE/avatars/2")"
ck "user with no photo -> 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$BASE/avatars/3")"
ck "nonexistent user -> 404 (same answer)" 404 "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$BASE/avatars/9999")"
ck "extension suffix does NOT bypass router" 404 "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$BASE/avatars/2.png")"
ck "upload without CSRF refused" 1 "$(curl -s -c "$J" -b "$J" -F "photo=@$FX\\good.jpg" -L "$BASE/profile/photo" | grep -c 'session has expired')"

echo
echo "--- E. remove ---"
c=$(csrf)
curl -s -o /dev/null -c "$J" -b "$J" -F "csrf_token=$c" "$BASE/profile/photo/remove"
ck "file unlinked on remove" "$BEFORE" "$(count_files)"
ck "sidebar back to initials" 1 "$(curl -s -b "$J" "$BASE/faculty/dashboard" | grep -c 'avatar-initials')"
ck "avatar route now 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$BASE/avatars/2")"

echo
echo "--- F. CSP + warnings ---"
ck "no inline style/onclick on /profile" 0 "$(curl -s -b "$J" "$BASE/profile" | grep -c 'style="\|onclick=')"
ck "no PHP warnings on /profile" 0 "$(curl -s -b "$J" "$BASE/profile" | grep -ci 'Warning\|Notice\|Fatal')"
ck "no warnings on /avatars with array id" 0 "$(curl -s -b "$J" "$BASE/avatars/2?x[]=1" | grep -ci 'Warning\|Notice')"

echo
echo "RESULT: $PASS passed, $FAIL failed"
