#!/usr/bin/env python3
import hashlib, json, os, pathlib, sys

root = pathlib.Path("public")
entries = []

files_root = root / "files"
if files_root.is_dir():
    for p in files_root.rglob("*"):
        if p.is_file():
            rel = p.relative_to(root).as_posix()
            h = hashlib.sha256()
            with open(p, "rb") as f:
                for chunk in iter(lambda: f.read(65536), b""):
                    h.update(chunk)
            entries.append({
                "path": rel,
                "size": p.stat().st_size,
                "sha256": h.hexdigest(),
                "url": "/" + rel,
            })

bundle = root / "bundle" / "ordersentinel-code.tar.gz"
if bundle.exists():
    h = hashlib.sha256()
    with open(bundle, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    entries.append({
        "path": "bundle/ordersentinel-code.tar.gz",
        "size": bundle.stat().st_size,
        "sha256": h.hexdigest(),
        "url": "/bundle/ordersentinel-code.tar.gz",
    })

(root / "index.json").write_text(json.dumps({"generated": True, "files": entries}, indent=2), encoding="utf-8")
print(f"[index] wrote {len(entries)} entries")
