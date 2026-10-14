# Shared campaign monitor cache

The dashboard and campaign monitor read one published snapshot for all browsers.
Only `zynervoxv2-campaign-monitor.service` calculates Bot IVR and agent statistics.
It uses the existing repository/Dialer monitor methods; counting semantics and
campaign Play/Stop behavior are unchanged.

The PHP CLI worker holds an exclusive `flock` for its lifetime. It refreshes every
five seconds, or after the current calculation finishes if that takes longer.
Refreshes never overlap. Each successful cycle writes a temporary JSON, sets mode
0640 and atomically renames it to the published snapshot. A failed cycle retains
the previous file and exits; systemd restarts it after five seconds, also replacing
broken database connections. No browser can trigger a recalculation.

## Runtime on mirmidon

- Service: `zynervoxv2-campaign-monitor.service`, running as `wwwrun:www`.
- Sources: `modules/admin/campaign_monitor_cache.php` and CLI-only
  `modules/admin/campaign_monitor_worker.php` under the deployed web root.
- Directory: `/var/lib/zynervoxv2/asterisk/campaign_monitor`, `wwwrun:www`, 0750.
- Snapshot: `snapshot.json`, 0640; lock: `worker.lock`.
- HTTP GET keeps `campaigns` and adds UTC `updated_at`, `age_seconds`, `stale`.
- Data older than 15 seconds remains available and is explicitly marked stale.
- Missing/corrupt cache returns unavailability. There is no direct SQL fallback
  in either initial page rendering or polling.
- POST Play/Stop still validates current live campaign/list/run services. It
  does not refresh the snapshot; visible state follows the next published cycle.

This reduces duplicated statistics queries as browser count grows. It still
calculates the original queries once per cycle, including when no browser is open.

## Deployment and checks

`installer/campaign-monitor.sh /absolute/staged-tree` targets the existing mirmidon
layout. It syntax-checks the four PHP sources, installs the private cache directory
and service, and waits for a fresh snapshot before switching the web readers.
Source files are root-owned; existing files receive timestamped backups.

```bash
systemctl status zynervoxv2-campaign-monitor.service
journalctl -u zynervoxv2-campaign-monitor.service -n 20
php src/features/admin/tests/campaign-cache.php
php src/features/admin/tests/campaign-play.php
```

The existing route smoke test can use `ZYNERVOX_MONITOR_TEST_USER` with an existing
active level-9 account when account revalidation is enabled. It does not log in over
HTTP, change accounts, launch workers or originate calls. Isolated controller
fixtures throw if GET attempts to query either statistics source.

Validation on 2026-10-14: both fixture suites passed locally and on mirmidon;
dashboard/monitor routes rendered through the isolated gate, authenticated API
and anonymous rejection passed, a second worker was rejected by the lock, and
the live service had zero restarts with two campaigns in a fresh snapshot.

## Rollback

Prepare the previous `campaign_monitor_view.php` and `campaign_monitor_api.php`
from the deployment backups or Git, deploy them together with their original
ownership/modes, and then disable/stop the cache service. The previous readers
resume their original direct statistics queries. Existing calls and Play/Stop
services require no changes. Do not restore unrelated files or IsolatedGate.
