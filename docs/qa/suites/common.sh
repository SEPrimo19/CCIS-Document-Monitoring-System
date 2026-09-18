#!/usr/bin/env bash
# Shared helpers for CCIS-DMS QA curl suites.
# Overridable, so a suite can be pointed at a different server without editing
# anything. That is not a convenience: the PHP dev server and Apache genuinely
# behave differently (the dev server serves any URI containing an extension
# straight from disk and never reads .htaccess), and a rule that breaks the app
# on Apache passes every suite here unless they can be re-run against it:
#
#   CCIS_BASE=http://localhost/ccis bash docs/qa/suites/suite_document_viewer.sh
BASE="${CCIS_BASE:-http://localhost:8000}"
# Resolves to this script's own directory, so the suites run from wherever the
# repo is checked out. They previously hard-coded a session scratchpad path,
# which is how this evidence was lost twice (see docs/qa/README.md).
QA="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# This curl.exe is a native Windows (mingw32) build: -F "field=@path" needs a
# Windows-style path (C:\...), NOT a Git-Bash POSIX path (/c/...) -- a POSIX
# path causes curl exit 26 "Failed to open/read local data from file" and
# looks like a silent request failure (HTTP 000) if stderr isn't checked.
# WQA is the Windows-style equivalent of QA, for every -F upload.
WQA=$(cygpath -w "$QA")

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "FAIL: $1"; }

# assert_eq <label> <expected> <actual>
assert_eq() {
  local label="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then pass "$label (got $actual)"; else fail "$label (expected $expected, got $actual)"; fi
}

result_line() {
  echo "RESULT: $PASS passed, $FAIL failed"
}

# get_csrf <jarfile> <path>  -> prints csrf token found in page body
get_csrf() {
  local jar="$1" path="$2"
  curl -s -c "$jar" -b "$jar" "$BASE$path" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed -E 's/.*value="([^"]*)".*/\1/'
}

# get_hidden <jarfile> <path> <fieldname>
get_hidden_field() {
  local jar="$1" path="$2" field="$3"
  curl -s -c "$jar" -b "$jar" "$BASE$path" | grep -o "name=\"$field\" value=\"[^\"]*\"" | head -1 | sed -E "s/.*value=\"([^\"]*)\".*/\1/"
}

# login <jarfile> <email> <password> -> returns http code of the POST /login (302 expected)
login() {
  local jar="$1" email="$2" password="$3"
  rm -f "$jar"
  local csrf
  csrf=$(get_csrf "$jar" "/login")
  curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" \
    --data-urlencode "email=$email" \
    --data-urlencode "password=$password" \
    --data-urlencode "csrf_token=$csrf" \
    "$BASE/login"
}

# body_contains <jarfile> <path> <needle> -> "yes"/"no"
body_contains() {
  local jar="$1" path="$2" needle="$3"
  if curl -s -c "$jar" -b "$jar" "$BASE$path" | grep -qF "$needle"; then echo "yes"; else echo "no"; fi
}

http_code() {
  local jar="$1"; shift
  curl -s -o /dev/null -w "%{http_code}" -c "$jar" -b "$jar" "$@"
}
