import os, re, zipfile
from pathlib import Path

SLUG = "order-sentinel"
repo = Path(__file__).resolve().parents[1]
src  = repo / SLUG
dist = repo / "dist"
dist.mkdir(exist_ok=True)

# Read version
version = "0.0.0"
main_php = src / f"{SLUG}.php"
if main_php.exists():
    m = re.search(r"^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)", main_php.read_text(encoding="utf-8"), re.M)
    if m:
        version = m.group(1)

zip_path = dist / f"OrderSentinel-{version}.zip"
if zip_path.exists():
    zip_path.unlink()

EXCLUDE_DIRS = {".git", "__pycache__", "node_modules", "vendor", "tests", "test", "tmp", "build"}
EXCLUDE_BASENAMES = {".DS_Store", "Thumbs.db"}
EXCLUDE_SUFFIXES = (".bak", ".orig", ".tmp", ".psd", ".ai")

def skip_rel(rel: str) -> bool:
    rel = rel.replace("\\", "/")
    if rel.startswith("mu-plugins/") or "/mu-plugins/" in rel:
        return True
    base = rel.rsplit("/", 1)[-1]
    if base in EXCLUDE_BASENAMES or base.endswith(EXCLUDE_SUFFIXES):
        return True
    return False

with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(src):
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
        rel_root = Path(root).relative_to(src)
        for f in files:
            rel_file = (rel_root / f).as_posix()
            if skip_rel(rel_file):
                continue
            z.write(Path(root) / f, arcname=f"{SLUG}/{rel_file}")

print(f"Created: {zip_path}")
