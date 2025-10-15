#!/usr/bin/env python3
import argparse, json, os, pathlib, sys, urllib.request

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

def json_url(url: str):
    try:
        with urllib.request.urlopen(url) as r:
            return json.load(r)
    except Exception as e:
        print(f"[warn] cannot read JSON {url}: {e}")
        return None

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--memory-base", required=True, help="Raw GitHub base to memory repo root, e.g. https://raw.githubusercontent.com/<user>/<repo>/main")
    p.add_argument("--project-name", default="OrderSentinel", help="Project name as in .greg/project-index.json")
    p.add_argument("--project-repo", default="https://github.com/meloyelo51/wp-ordersentinel", help="Project repo URL to match")
    p.add_argument("--dest", required=True, help="Destination root (e.g., public/projects/order-sentinel)")
    args = p.parse_args()

    mem = args.memory_base.rstrip("/")
    dest_root = pathlib.Path(args.dest)
    dest_docs = dest_root / "docs"
    dest_tickets = dest_root / "tickets"

    # 1) read master index of projects
    idx_url = f"{mem}/.greg/project-index.json"
    idx = json_url(idx_url) or {}
    projects = idx.get("projects") or []

    # pick project by repo URL or name
    chosen = None
    for pr in projects:
        if pr.get("repo") == args.project_repo:
            chosen = pr; break
    if not chosen:
        for pr in projects:
            if pr.get("name") == args.project_name:
                chosen = pr; break

    # determine slug from paths[0] (e.g. "order-sentinel")
    slug = None
    if chosen and isinstance(chosen.get("paths"), list) and chosen["paths"]:
        slug = chosen["paths"][0].strip("/")

    if not slug:
        # fallback to order-sentinel as before
        slug = "order-sentinel"
        print(f"[warn] could not derive slug from project index; using fallback '{slug}'")

    proj_base = f"{mem}/projects/{slug}"

    # 2) project-level manifest
    project_manifest = json_url(f"{proj_base}/_manifest.json") or {}
    docs_manifest_path = project_manifest.get("docs_manifest") or f"projects/{slug}/docs/_manifest.json"
    tickets_manifest_path = project_manifest.get("tickets_manifest") or f"projects/{slug}/tickets/_manifest.json"

    # 3) docs manifest → roadmap + any extras
    docs_manifest = json_url(f"{mem}/{docs_manifest_path}") or {}
    for name in docs_manifest.get("roadmap", []):
        fetch(f"{proj_base}/docs/{name}", dest_docs / name)
    for name in docs_manifest.get("extra_docs", []):
        fetch(f"{proj_base}/docs/{name}", dest_docs / name)

    # 4) tickets manifest → list of ticket files
    tickets_manifest = json_url(f"{mem}/{tickets_manifest_path}") or {}
    for name in tickets_manifest.get("tickets", []):
        fetch(f"{proj_base}/tickets/{name}", dest_tickets / name)

    # 5) write a combined export (always)
    export = dest_root / "all_tickets_export.md"
    export.parent.mkdir(parents=True, exist_ok=True)
    any_file = False
    if dest_tickets.exists():
        for f in sorted(dest_tickets.glob("*.md")):
            any_file = True
            export.write_text((export.read_text(encoding="utf-8") if export.exists() else "") +
                              f"\n\n# ===== {f.name} =====\n" +
                              f.read_text(encoding="utf-8"), encoding="utf-8")
    if not any_file:
        export.write_text("# (no tickets found in memory repo manifest)\n", encoding="utf-8")
    print(f"[write] {export}")

if __name__ == "__main__":
    main()
