---
title: "Fix redeclare fatal; build script manifest; GitHub updater"
date: 2025-09-23
---

### Fixed
- Removed duplicate `get_recent_rows()` definition causing fatal errors.

### Improved
- Build script now prints a ZIP manifest of the file it just created (no need to run `unzip -l`).

### Added
- Optional GitHub updater with Settings for repo, token, and **Stable** vs **Beta** channels. Uses GitHub Releases; expects a correctly packaged ZIP asset.
