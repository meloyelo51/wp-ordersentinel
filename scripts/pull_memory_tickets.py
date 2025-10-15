#!/usr/bin/env python3
import argparse, json, os, sys, urllib.request, pathlib

def fetch(url: str, dest: pathlib.Path) -> bool:
    try:
        dest.parent.mkdir(parents=True, exist_ok=True)
        with urllib.request.urlopen(url) as r, open(dest, 'wb') as f:
            f.write(r.read())
        print(f"[pull] {url} -> {dest}")
        return True
    except Exception as e:
        print(f"[skip] {url} ({e})")
        return False

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--base", required=True, help="Base raw URL to memory repo's projects/order-sentinel")
    p.add_argument("--dest", required=True, help="Destination root (e.g., public/projects/order-sentinel)")
    args = p.parse_args()

    base = args.base.rstrip("/")
    dest_root = pathlib.Path(args.dest)

    # docs
    fetch(f"{base}/docs/_manifest.json", dest_root / "docs" / "_manifest.json")
    fetch(f"{base}/docs/roadmap.md",     dest_root / "docs" / "roadmap.md")

    # tickets manifest
    man_path = dest_root / "tickets" / "_manifest.json"
    if fetch(f"{base}/tickets/_manifest.json", man_path):
        try:
            data = json.loads(man_path.read_text(encoding="utf-8"))
            tickets = data.get("tickets") or []
        except Exception as e:
            print(f"[warn] bad _manifest.json: {e}")
            tickets = []
        for name in tickets:
            fetch(f"{base}/tickets/{name}", dest_root / "tickets" / name)

    # combined export (always emit)
    export = dest_root / "all_tickets_export.md"
    export.parent.mkdir(parents=True, exist_ok=True)
    with open(export, "w", encoding="utf-8") as out:
        any_file = False
        tdir = dest_root / "tickets"
        if tdir.exists():
            for f in sorted(tdir.glob("*.md")):
                any_file = True
                out.write(f"\n\n# ===== {f.name} =====\n")
                out.write(f.read_text(encoding="utf-8"))
        if not any_file:
            out.write("# (no tickets found in memory repo manifest)\n")
    print(f"[write] {export}")

if __name__ == "__main__":
    main()
