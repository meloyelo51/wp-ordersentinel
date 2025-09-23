#!/usr/bin/env python3
"""
Build a WordPress-friendly plugin ZIP with forward-slash paths and print a manifest
of the ZIP contents so you don't need 'unzip -l'.

Usage (from repo root):
  py scripts/build-plugin-zip.py
  py scripts/build-plugin-zip.py order-sentinel 0.2.2
"""

import os, sys, re, zipfile, time

def derive_version(main_php_path: str) -> str:
    version = "0.1.0"
    try:
        with open(main_php_path, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                m = re.match(r'^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)\s*$', line)
                if m:
                    return m.group(1)
    except Exception:
        pass
    return version

def main() -> None:
    root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    plugin_dir = sys.argv[1] if len(sys.argv) > 1 else "order-sentinel"
    plugin_path = os.path.join(root, plugin_dir)

    if not os.path.isdir(plugin_path):
        print(f"ERROR: Plugin dir not found: {plugin_path}")
        sys.exit(1)

    main_php = os.path.join(plugin_path, "order-sentinel.php")
    if not os.path.isfile(main_php):
        print(f"ERROR: {plugin_dir}/order-sentinel.php missing.")
        sys.exit(1)

    version = sys.argv[2] if len(sys.argv) > 2 else derive_version(main_php)

    dist_dir = os.path.join(root, "dist")
    os.makedirs(dist_dir, exist_ok=True)
    zip_path = os.path.join(dist_dir, f"OrderSentinel-{version}.zip")

    slug = os.path.basename(plugin_path.rstrip("/\\"))
    entries = []
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for folder, _subdirs, files in os.walk(plugin_path):
            rel_folder = os.path.relpath(folder, plugin_path)
            parts = [] if rel_folder in (".", "") else rel_folder.split(os.sep)
            if any(p in (".git", "node_modules", "vendor") for p in parts):
                continue
            for name in files:
                if name in ("Thumbs.db", ".DS_Store"):
                    continue
                abs_path = os.path.join(folder, name)
                rel = os.path.relpath(abs_path, plugin_path).replace(os.sep, "/")
                arcname = f"{slug}/{rel}"
                zf.write(abs_path, arcname)
                entries.append(arcname)

    print(f"Created: {zip_path}")
    print("ZIP manifest:")
    for e in entries:
        print(" -", e)

if __name__ == "__main__":
    main()
