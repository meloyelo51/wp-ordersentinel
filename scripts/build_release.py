# scripts/build_release.py

## How To Run ##
# Default (no tag, exclude MU/testing, compile changelog, update readme):
# python scripts/build_release.py

# Include MU/testing in the ZIP (internal test build):
# EXCLUDE_MU=0 python scripts/build_release.py

# Skip changelog/readme updates (pure ZIP build only):
# BUILD_CHANGELOG=0 UPDATE_README=0 python scripts/build_release.py

# Create a git tag v<version> after building:
# TAG=1 python scripts/build_release.py
# git push && git push --tags



import os, re, zipfile, subprocess, datetime
from pathlib import Path

ROOT        = Path.cwd()
PLUGIN_DIR  = ROOT / "order-sentinel"
BOOTSTRAP   = PLUGIN_DIR / "order-sentinel.php"
READMETXT   = PLUGIN_DIR / "readme.txt"
CHANGELOG   = ROOT / "CHANGELOG.md"
CLDIR       = ROOT / "changelog.d"
UNREL       = CLDIR / "unreleased"

# ---- Env toggles (all optional) ----
EXCLUDE_MU      = os.environ.get("EXCLUDE_MU", "1") == "1"   # exclude mu/testing dirs in ZIP (default: yes)
BUILD_CHANGELOG = os.environ.get("BUILD_CHANGELOG", "1") == "1"  # compile changelog.d into CHANGELOG.md (default: yes)
UPDATE_README   = os.environ.get("UPDATE_README", "1") == "1"    # update readme "Stable tag" + changelog block (default: yes)
TAG             = os.environ.get("TAG", "0") == "1"          # create git tag v<version> (default: no)
REPO            = os.environ.get("REPO", "meloyelo51/wp-ordersentinel")

def read(p: Path) -> str:
    return p.read_text(encoding="utf-8", errors="ignore")

def write(p: Path, s: str):
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(s, encoding="utf-8")

def detect_version() -> str:
    if not BOOTSTRAP.exists():
        raise SystemExit("[ERROR] order-sentinel/order-sentinel.php not found")
    src = read(BOOTSTRAP)
    m = re.search(r"(?m)^\s*\*?\s*Version:\s*([0-9A-Za-z._-]+)", src)
    if m:
        return m.group(1)
    # fallback: common constants
    for pat in [
        r"define\s*\(\s*'ORDER_SENTINEL_VERSION'\s*,\s*'([^']+)'",
        r"define\s*\(\s*'ORDERSENTINEL_VERSION'\s*,\s*'([^']+)'",
        r"\bconst\s+ORDER_SENTINEL_VERSION\s*=\s*'([^']+)'",
        r"\bconst\s+VERSION\s*=\s*'([^']+)'",
    ]:
        mm = re.search(pat, src, flags=re.I)
        if mm:
            return mm.group(1)
    raise SystemExit("[ERROR] Could not detect version from plugin header or constants")

VERSION = detect_version()
TODAY   = datetime.date.today().strftime("%Y-%m-%d")
print(f"[info] Detected version: {VERSION}")

# ---- Optionally compile changelog.d ----
if BUILD_CHANGELOG:
    if CLDIR.exists() and UNREL.exists() and any(UNREL.glob("*.md")):
        cats = {"Added":[],"Changed":[],"Improved":[],"Fixed":[],"Security":[],"Deprecated":[],"Removed":[]}
        for f in sorted(UNREL.glob("*.md")):
            n = f.name.lower()
            if   n.startswith("added-"):      cats["Added"].append(f)
            elif n.startswith("changed-"):    cats["Changed"].append(f)
            elif n.startswith("improved-"):   cats["Improved"].append(f)
            elif n.startswith("fixed-"):      cats["Fixed"].append(f)
            elif n.startswith("security-"):   cats["Security"].append(f)
            elif n.startswith("deprecated-"): cats["Deprecated"].append(f)
            elif n.startswith("removed-"):    cats["Removed"].append(f)
            else:                              cats["Changed"].append(f)

        section = [f"## [{VERSION}] — {TODAY}\n"]
        order = ["Added","Changed","Improved","Fixed","Security","Deprecated","Removed"]
        for cat in order:
            files = cats[cat]
            if not files: continue
            section.append(f"\n### {cat}\n")
            for f in files:
                section.append(read(f).rstrip() + "\n")
        section_text = "\n".join(section).rstrip() + "\n\n"

        if CHANGELOG.exists():
            text = read(CHANGELOG)
        else:
            text = "# Changelog\n\nAll notable changes to this project are documented here.\n\n"

        if re.search(rf"(?m)^##\s*\[{re.escape(VERSION)}\]\b", text):
            print(f"[ok ] CHANGELOG already has {VERSION}")
        else:
            m = re.search(r"(?m)^#\s*Changelog\s*$", text)
            if m:
                head_end = m.end()
                m2 = re.search(r"\n\s*\n", text[head_end:])
                insert_at = head_end + (m2.end() if m2 else 0)
                out = text[:insert_at] + "\n" + section_text + text[insert_at:]
            else:
                out = section_text + text
            write(CHANGELOG, out)
            print(f"[write] {CHANGELOG} ← compiled from changelog.d/unreleased")

        # archive unreleased to changelog.d/<version>/
        relver = CLDIR / VERSION
        relver.mkdir(parents=True, exist_ok=True)
        moved = 0
        for f in list(UNREL.glob("*.md")):
            dest = relver / f.name
            if not dest.exists():
                f.rename(dest)
                moved += 1
        print(f"[move] archived {moved} fragment(s) -> changelog.d/{VERSION}/")
    else:
        print("[info] no unreleased fragments; skipping changelog compile")
else:
    print("[skip] BUILD_CHANGELOG=0 → changelog not compiled")

# ---- Optionally update readme.txt ----
if UPDATE_README and READMETXT.exists():
    txt = read(READMETXT)
    txt = re.sub(r"(?im)^(Stable tag:\s*)\S+(\s*)$", rf"\g<1>{VERSION}\2", txt)
    new_block = (
        f"= {VERSION} = ({TODAY})\n"
        "* Update: See CHANGELOG.md for details.\n\n"
    )
    if "== Changelog ==" not in txt:
        txt = txt.rstrip() + "\n\n== Changelog ==\n" + new_block
    elif not re.search(rf"(?m)^=\s*{re.escape(VERSION)}\s*=", txt):
        txt = re.sub(r"(?m)^(==\s*Changelog\s*==\s*\n)", r"\1"+new_block, txt, count=1)
    write(READMETXT, txt)
    print(f"[write] {READMETXT} updated (Stable tag + version block)")
elif UPDATE_README:
    print("[skip] no readme.txt found (ok)")

# ---- Build ZIP (exclude MU/testing by default) ----
DIST     = ROOT / "dist"; DIST.mkdir(exist_ok=True)
ZIP_PATH = DIST / f"OrderSentinel-{VERSION}.zip"

EXCLUDE_DIRS = {
    ".git", ".github", ".vscode", "__pycache__", "node_modules",
    "dist", "dist-old", "old", "tmp", "temp", "tests", "examples", "sample", "sandbox",
}
MU_DIR_NAMES = {"mu", "mu-plugins", "mu_plugins", "muplugins", "sentinels"}
if EXCLUDE_MU:
    EXCLUDE_DIRS |= MU_DIR_NAMES

EXCLUDE_FILES_SUFFIX = {".ps1", ".sh~", ".bak", ".tmp", ".swp", ".DS_Store"}
EXACT_SKIP = {".DS_Store", "Thumbs.db"}

def should_skip(rel: Path) -> bool:
    parts = {p.lower() for p in rel.parts[:-1]}  # directory parts only
    if parts & EXCLUDE_DIRS:
        return True
    name = rel.name
    if name in EXACT_SKIP:
        return True
    for suf in EXCLUDE_FILES_SUFFIX:
        if name.endswith(suf):
            return True
    return False

with zipfile.ZipFile(ZIP_PATH, "w", compression=zipfile.ZIP_DEFLATED) as z:
    for p in PLUGIN_DIR.rglob("*"):
        if p.is_dir(): continue
        rel = p.relative_to(PLUGIN_DIR)
        if should_skip(rel): continue
        z.write(p, f"order-sentinel/{rel.as_posix()}")

print(f"[zip ] {ZIP_PATH} (EXCLUDE_MU={'1' if EXCLUDE_MU else '0'})")

# ---- Optional tag (v<version>) ----
if TAG:
    try:
        subprocess.run(["git","add","-A"], check=True)
        subprocess.run(["git","commit","-m", f"release: {VERSION} (dist only)"], check=False)
        subprocess.run(["git","tag","-a", f"v{VERSION}", "-m", f"Release {VERSION}"], check=True)
        print(f"[tag ] created tag v{VERSION} (push with: git push && git push --tags)")
    except Exception as e:
        print(f"[warn] tagging failed: {e}")
else:
    print("[info] TAG=0 → skipping tag")
