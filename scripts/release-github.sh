#!/usr/bin/env bash
set -euo pipefail

# Release helper for OrderSentinel using GitHub CLI (preferred) or raw API.
# Usage:
#   ./scripts/release-github.sh [-r owner/repo] [-v 0.2.2] [-t v0.2.2] [--beta] [--draft] [--notes "text"] [--asset path.zip]

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

repo=""
version=""
tag=""
beta="false"
draft="false"
notes=""
asset=""

die() { echo "Error: $*" >&2; exit 1; }

# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    -r|--repo) repo="$2"; shift 2;;
    -v|--version) version="$2"; shift 2;;
    -t|--tag) tag="$2"; shift 2;;
    --beta) beta="true"; shift;;
    --draft) draft="true"; shift;;
    -n|--notes) notes="$2"; shift 2;;
    -a|--asset) asset="$2"; shift 2;;
    -h|--help)
      sed -n '1,80p' "$0"; exit 0;;
    *) die "Unknown arg: $1";;
  esac
done

# Repo: arg -> env -> git origin
if [[ -z "$repo" ]]; then
  if [[ -n "${GITHUB_REPO:-}" ]]; then
    repo="$GITHUB_REPO"
  else
    origin="$(git -C "$ROOT" remote get-url origin 2>/dev/null || true)"
    if [[ "$origin" =~ github\.com[:/]+([^/]+/[^/.]+) ]]; then
      repo="${BASH_REMATCH[1]}"
    fi
  fi
fi
[[ -n "$repo" ]] || die "GitHub repo not set. Use -r owner/repo or export GITHUB_REPO."

# Version: arg -> parse from plugin header
if [[ -z "$version" ]]; then
  main="$ROOT/order-sentinel/order-sentinel.php"
  [[ -f "$main" ]] || die "Missing $main"
  version="$(awk -F': ' '/^\s*\*\s*Version:/{print $2; exit}' "$main" | tr -d ' \r')"
  [[ -n "$version" ]] || die "Could not parse Version from plugin header."
fi

# Tag
[[ -n "$tag" ]] || tag="v${version}"

# Asset
[[ -n "$asset" ]] || asset="$ROOT/dist/OrderSentinel-${version}.zip"

# Build if needed
if [[ ! -f "$asset" ]]; then
  echo "Building ZIP for version $version..."
  if command -v py >/dev/null 2>&1; then
    py "$ROOT/scripts/build-plugin-zip.py" "order-sentinel" "$version"
  elif command -v python3 >/dev/null 2>&1; then
    python3 "$ROOT/scripts/build-plugin-zip.py" "order-sentinel" "$version"
  else
    python "$ROOT/scripts/build-plugin-zip.py" "order-sentinel" "$version"
  fi
  [[ -f "$asset" ]] || die "Expected asset not found after build: $asset"
fi

# Notes default
if [[ -z "$notes" ]]; then
  notes="OrderSentinel ${tag}"
  if [[ -d "$ROOT/CHANGELOG.d" ]]; then
    add="$(ls -1 "$ROOT/CHANGELOG.d" 2>/dev/null | sed 's/^/ - /' || true)"
    [[ -n "$add" ]] && notes="${notes}\n\nFragments:\n${add}"
  fi
fi

echo "Repo:    $repo"
echo "Version: $version"
echo "Tag:     $tag"
echo "Beta:    $beta"
echo "Draft:   $draft"
echo "Asset:   $asset"
# Print single-line version of notes to keep logs tidy
echo "Notes:   ${notes//$'\n'/\\n}"

# Locate gh (Git Bash might not have it on PATH even if installed)
GH_BIN="$(command -v gh || true)"
if [[ -z "$GH_BIN" ]]; then
  # Try common Windows install locations (MSYS paths)
  cands=(
    "/c/Program Files/GitHub CLI/gh.exe"
    "/c/Program Files (x86)/GitHub CLI/gh.exe"
    "/c/Users/${USERNAME:-$USER}/AppData/Local/Programs/GitHub CLI/gh.exe"
  )
  for p in "${cands[@]}"; do
    if [[ -x "$p" ]]; then GH_BIN="$p"; break; fi
  done
fi

if [[ -n "$GH_BIN" ]]; then
  echo "Using GitHub CLI at: $GH_BIN"
  args=(release create "$tag" "$asset" --repo "$repo" --title "OrderSentinel $tag" --notes "$notes")
  [[ "$beta" == "true" ]] && args+=(--prerelease)
  [[ "$draft" == "true" ]] && args+=(--draft)
  "$GH_BIN" "${args[@]}"
  echo "Release created via gh."
  exit 0
fi

# Fallback: raw API requires GITHUB_TOKEN and jq
[[ -n "${GITHUB_TOKEN:-}" ]] || die "GITHUB_TOKEN not set and 'gh' not available."
command -v jq >/dev/null 2>&1 || die "jq not found. Install jq or use gh CLI."

api="https://api.github.com/repos/${repo}/releases"
json="$(jq -n \
  --arg tag_name "$tag" \
  --arg name "OrderSentinel $tag" \
  --arg body "$notes" \
  --argjson draft $([[ "$draft" == "true" ]] && echo true || echo false) \
  --argjson prerelease $([[ "$beta" == "true" ]] && echo true || echo false) \
  '{tag_name:$tag_name,name:$name,body:$body,draft:$draft,prerelease:$prerelease}')"

resp="$(curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
                 -H "Accept: application/vnd.github+json" \
                 -H "User-Agent: OrderSentinel-Release" \
                 -d "$json" "$api")" || die "Release create failed."
upload_url="$(echo "$resp" | jq -r '.upload_url' | sed 's/{.*}//')"
rel_html="$(echo "$resp" | jq -r '.html_url')"
rel_id="$(echo "$resp" | jq -r '.id')"
[[ -n "$upload_url" && "$upload_url" != "null" ]] || die "No upload_url in response."

name="$(basename "$asset")"
echo "Uploading asset $name..."
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
     -H "Content-Type: application/zip" \
     --data-binary @"$asset" \
     "${upload_url}?name=${name}" >/dev/null || die "Asset upload failed."

echo "Release created: $rel_html (id=$rel_id)"
