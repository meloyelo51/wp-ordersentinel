# scripts/release-github.ps1
@'
Release helper for OrderSentinel using GitHub CLI (preferred) or raw API.
Usage:
  pwsh scripts/release-github.ps1 [-Repo owner/repo] [-Version 0.2.2] [-Tag v0.2.2] [-Beta] [-Draft] [-Notes "text"] [-Asset path.zip]
Defaults:
  - Repo: parsed from git remote origin (or $env:GITHUB_REPO); else required
  - Version: parsed from order-sentinel/order-sentinel.php
  - Tag: "v<Version>"
  - Asset: dist/OrderSentinel-<Version>.zip (built if missing)
'@ | Out-Null

Param(
  [string]$Repo,
  [string]$Version,
  [string]$Tag,
  [switch]$Beta,
  [switch]$Draft,
  [string]$Notes,
  [string]$Asset
)

$ErrorActionPreference = 'Stop'
function Die([string]$Msg){ Write-Error $Msg; exit 1 }

# Resolve repo root
$ROOT = Resolve-Path (Join-Path $PSScriptRoot '..')

# Repo from args -> env -> git origin
if (-not $Repo) {
  if ($env:GITHUB_REPO) {
    $Repo = $env:GITHUB_REPO
  } else {
    try {
      $origin = git -C $ROOT remote get-url origin 2>$null
      if ($origin -match 'github\.com[:/]+([^/]+/[^/.]+)') { $Repo = $Matches[1] }
    } catch {}
  }
}
if (-not $Repo) { Die "GitHub repo not set. Use -Repo owner/repo or set GITHUB_REPO." }

# Version from args -> parse from plugin header
if (-not $Version) {
  $main = Join-Path $ROOT 'order-sentinel/order-sentinel.php'
  if (-not (Test-Path $main)) { Die "Missing $main" }
  $line = (Get-Content $main -Raw) -split "`n" | Where-Object { $_ -match '^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)\s*$' } | Select-Object -First 1
  if ($line -match 'Version:\s*([0-9A-Za-z.\-]+)') { $Version = $Matches[1].Trim() }
}
if (-not $Version) { Die "Could not parse Version from plugin header." }

# Tag default
if (-not $Tag) { $Tag = "v$Version" }

# Asset default
if (-not $Asset) { $Asset = Join-Path $ROOT "dist/OrderSentinel-$Version.zip" }

# Build if missing
if (-not (Test-Path $Asset)) {
  Write-Host "Building ZIP for version $Version..."
  $py = (Get-Command py -ErrorAction SilentlyContinue)?.Source
  if ($py) {
    & $py (Join-Path $ROOT 'scripts/build-plugin-zip.py') 'order-sentinel' $Version
  } else {
    $python = (Get-Command python3 -ErrorAction SilentlyContinue)?.Source
    if (-not $python) { $python = (Get-Command python -ErrorAction SilentlyContinue)?.Source }
    if (-not $python) { Die "No Python found. Install Python or use -Asset with a prebuilt ZIP." }
    & $python (Join-Path $ROOT 'scripts/build-plugin-zip.py') 'order-sentinel' $Version
  }
  if (-not (Test-Path $Asset)) { Die "Expected asset not found after build: $Asset" }
}

# Notes default
if (-not $Notes) {
  $Notes = "OrderSentinel $Tag"
  $clDir = Join-Path $ROOT 'CHANGELOG.d'
  if (Test-Path $clDir) {
    $frags = Get-ChildItem $clDir -File | ForEach-Object { " - $($_.Name)" } | Out-String
    if ($frags.Trim()) { $Notes = "$Notes`n`nFragments:`n$frags" }
  }
}

Write-Host "Repo:    $Repo"
Write-Host "Version: $Version"
Write-Host "Tag:     $Tag"
Write-Host "Beta:    $($Beta.IsPresent)"
Write-Host "Draft:   $($Draft.IsPresent)"
Write-Host "Asset:   $Asset"
Write-Host "Notes:   $Notes"

# Prefer GitHub CLI
$gh = Get-Command gh -ErrorAction SilentlyContinue
if ($gh) {
  Write-Host "Using GitHub CLI..."
  $args = @('release','create',$Tag,$Asset,'--repo',$Repo,'--title',"OrderSentinel $Tag",'--notes',"$Notes")
  if ($Beta)  { $args += '--prerelease' }
  if ($Draft) { $args += '--draft' }
  & $gh @args
  Write-Host "Release created via gh."
  exit 0
}

# Fallback: raw API with GITHUB_TOKEN
if (-not $env:GITHUB_TOKEN) { Die "GITHUB_TOKEN not set and 'gh' not available." }
$hdrs = @{
  'Authorization' = "token $($env:GITHUB_TOKEN)"
  'Accept'        = 'application/vnd.github+json'
  'User-Agent'    = 'OrderSentinel-Release'
}
$body = @{
  tag_name   = $Tag
  name       = "OrderSentinel $Tag"
  body       = $Notes
  draft      = [bool]$Draft
  prerelease = [bool]$Beta
} | ConvertTo-Json -Depth 4

$rel = Invoke-RestMethod -Method Post -Uri "https://api.github.com/repos/$Repo/releases" -Headers $hdrs -ContentType 'application/json' -Body $body
$uploadUrl = $rel.upload_url -replace '{.*$',''
if (-not $uploadUrl) { Die "No upload_url received." }

$name = [IO.Path]::GetFileName($Asset)
Write-Host "Uploading asset $name ..."
Invoke-WebRequest -Method Post -Uri "$uploadUrl?name=$name" -Headers @{ Authorization = "token $($env:GITHUB_TOKEN)"; "User-Agent"="OrderSentinel-Release" } -InFile $Asset -ContentType 'application/zip' | Out-Null
Write-Host "Release created: $($rel.html_url) (id=$($rel.id))"
