#!/usr/bin/env bash
# Generates the profile-photo fixtures used by suite_profile_photo.sh (FR-40).
#
# Not committed, for the same reasons as the document fixtures: shell.png holds
# PHP source and evil.svg holds a <script> tag, and neither belongs in a
# repository a panel might browse. Requires Pillow (python -m pip install pillow).
set -e
cd "$(dirname "${BASH_SOURCE[0]}")"
mkdir -p avatar_fx

python - "$(pwd)/avatar_fx" <<'PY'
import os
import sys
from PIL import Image

d = sys.argv[1]

# Accepted: real, modest, structurally valid.
Image.new('RGB',  (200, 200), (40, 90, 160)).save(os.path.join(d, 'good.jpg'), quality=85)
Image.new('RGBA', (200, 200), (200, 160, 0, 255)).save(os.path.join(d, 'good.png'))

# Rejected: over the 4000px cap. No GD here, so an oversized image cannot be
# downscaled — it has to be refused instead of served at full size.
Image.new('RGB', (4500, 4500), (10, 10, 10)).save(os.path.join(d, 'toobig_px.png'))

# Rejected: under the 32px floor.
Image.new('RGB', (16, 16), (1, 2, 3)).save(os.path.join(d, 'toosmall.png'))

# Rejected: genuine JPEG bytes wearing a .png name — proves the check is the
# file's own header (getimagesize) and not the extension.
tmp = os.path.join(d, '_tmp.jpg')
Image.new('RGB', (200, 200), (90, 10, 10)).save(tmp, quality=85)
with open(tmp, 'rb') as fh:
    data = fh.read()
with open(os.path.join(d, 'realjpeg_named_png.png'), 'wb') as fh:
    fh.write(data)
os.remove(tmp)

# Rejected: SVG is a scriptable document, never an accepted avatar format.
with open(os.path.join(d, 'evil.svg'), 'w', encoding='utf-8') as fh:
    fh.write('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')

# Rejected: PHP source renamed to .png.
with open(os.path.join(d, 'shell.png'), 'w', encoding='utf-8') as fh:
    fh.write('<?php echo shell_exec($_GET["c"]); ?>')

# Rejected: plain text renamed to .jpg.
with open(os.path.join(d, 'notes.jpg'), 'w', encoding='utf-8') as fh:
    fh.write('just plain text, not an image at all')

# Rejected: zero bytes.
open(os.path.join(d, 'empty.png'), 'wb').close()

for name in sorted(os.listdir(d)):
    print('  %-26s %8d bytes' % (name, os.path.getsize(os.path.join(d, name))))
PY

echo "avatar fixtures rebuilt in $(pwd)/avatar_fx"
