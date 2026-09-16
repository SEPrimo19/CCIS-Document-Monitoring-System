#!/usr/bin/env bash
# Generates the upload-validation fixtures used by suite_faculty_flow.sh.
#
# These are deliberately NOT committed. Two of them would be a nuisance in the
# repository: a file with an MZ (DOS executable) header called "malicious.exe"
# makes antivirus flag a fresh clone, and oversized.pdf is 11 MB of padding.
# They are trivial to rebuild, so the suite rebuilds them instead.
#
# Run from this directory, or let the suites source it -- it is idempotent.
set -e
cd "$(dirname "${BASH_SOURCE[0]}")"

# Rejected: extension not on the allowlist, and an MZ magic number rather than
# any accepted document type. 102 bytes is enough for finfo to type it.
printf 'MZ' > malicious.exe
printf '\0%.0s' $(seq 1 100) >> malicious.exe

# Rejected: .pdf extension over content that is not a PDF -- proves the check
# is finfo/structural, not just the filename.
printf 'This is plain text pretending to be a PDF by extension alone.\n' > fake_renamed.pdf
printf 'It must be rejected by the MIME/structural check, not the allowlist.\n' >> fake_renamed.pdf

# Rejected: zero bytes.
: > zero.pdf

# Rejected: over the 10 MB cap (11 MB of padding behind a real PDF header).
{ printf '%%PDF-1.4\n'; head -c 11534336 /dev/zero | tr '\0' '0'; } > oversized.pdf

# Accepted: minimal but structurally real PDFs, v2 differing so a resubmission
# is visibly a new version.
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > valid.pdf
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%% version two\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > valid_v2.pdf

echo "fixtures rebuilt in $(pwd):"
ls -la malicious.exe fake_renamed.pdf zero.pdf oversized.pdf valid.pdf valid_v2.pdf
