"""Re-pack ai-product-inserter using forward-slash paths (WordPress-compatible)."""
import os
import zipfile

SRC_DIR = r"C:\Users\yoshi\OneDrive\デスクトップ\Codex\plugin-patches\build"
OUT_ZIP = r"C:\Users\yoshi\Downloads\ai-product-inserter-1.6.0.zip"

# Remove existing zip
if os.path.exists(OUT_ZIP):
    os.remove(OUT_ZIP)

with zipfile.ZipFile(OUT_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, _, files in os.walk(SRC_DIR):
        for name in files:
            full = os.path.join(root, name)
            # Path relative to SRC_DIR, with forward slashes
            rel = os.path.relpath(full, SRC_DIR).replace(os.sep, "/")
            zf.write(full, rel)

# Verify
with zipfile.ZipFile(OUT_ZIP, "r") as zf:
    names = zf.namelist()
    print(f"Total entries: {len(names)}")
    print("First 5 entries:")
    for n in names[:5]:
        print(f"  {n}")
