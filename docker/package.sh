#!/usr/bin/env bash
# Build the admin app and produce dist/smart-bulk-discount-<version>.zip (install via
# Plugins → Add New → Upload).
set -euo pipefail
cd "$(dirname "$0")/.."
(cd admin-app && npm run build)
VERSION=$(grep -m1 "Version:" smart-bulk-discount/smart-bulk-discount.php | awk '{print $NF}')
mkdir -p dist
OUT="dist/smart-bulk-discount-${VERSION}.zip"
rm -f "$OUT"
python3 - "$OUT" <<'EOF'
import sys, zipfile, pathlib
out = sys.argv[1]
root = pathlib.Path("smart-bulk-discount")
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for p in sorted(root.rglob("*")):
        if p.is_file():
            z.write(p, p.as_posix())
print("wrote", out)
EOF
