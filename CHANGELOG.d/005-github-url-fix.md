## 0.2.7 — GitHub updater URL fix
- Fixed releases API URL building: we now encode `owner` and `repo` **separately** (slashes were becoming `%2F`).
- Updater diagnostics should now show HTTP 200 and the remote version/asset correctly.
