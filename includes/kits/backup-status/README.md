# backup-status kit

Backup status and history across UpdraftPlus, Duplicator and BackWPup, whichever are active.
Read-only.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/backup-status` | read | Per provider: last backup and last successful one (result, contents, size, storage names), running/queued jobs, next scheduled run, whether a backup could be started (`trigger`), plus the newest successful backup across providers. |
| `wppilot/backup-list` | read | Recent backups per provider, newest first; `limit` 1–100 (default 20). |

Safe fields only: times (site timezone and UTC), result, contents, size, storage names, labels,
error/warning counts. Never archive paths or file names, download URLs, job nonces or storage
credentials.

The kit is skipped unless UpdraftPlus, Duplicator 5 or BackWPup is active. Each ability registers
only while its name is unclaimed (`Runtime\unclaimed()`), so a plugin that already registers the
same name keeps it.

## Vendor data, verified 2026-09-29 on WordPress 7.1.2

- **UpdraftPlus 1.26.8**: `UpdraftPlus_Backup_History::get_history()` (sets keyed by start time,
  per-entity file lists and `<entity>-size`), `updraft_last_backup` (the only recorded verdict,
  for the latest job), `updraft_backup_resume` cron events for running jobs and the
  `updraft_backupnow_*` events for queued ones, `updraft_backup` / `updraft_backup_database` for
  the schedule, `updraft_service` + `$updraftplus->backup_methods` for storage names.
- **Duplicator 5.0.4**: `Duplicator\Package\DupPackage::dbSelect()` over
  `{base_prefix}duplicator_backups`; status 100 complete, 0–99 building, negative failed or
  cancelled; `created` is UTC. Duplicator Pro and Duplicator before 5.0 are not read.
- **BackWPup 5.7.6**: runs from `BackWPup_Job::read_logheader()` over its logs folder, jobs from
  `BackWPup_Option::get_job_ids()` / `get_job()` (`tempjob` hidden), running job from
  `BackWPup_Job::get_working_data()`, schedule from `wp_next_scheduled('backwpup_cron', ['arg' =>
  $id])`. Logs outlive archives, so a successful run of a job whose only destination is `FOLDER`
  counts only while the archive its log names is still in the job's `backupdir`; otherwise it is
  `kind: "deleted"`. Runs that went to remote storage are counted with `archive_verified: false`.

## Host needs

`Runtime\can_run()` plus `manage_options`, and `Runtime\unclaimed()` (runtime 1.2).

<!-- kit-export:omit -->
Inside WPPilot this kit carries the two reads WPPilot Pro 1.10.0's `backups` kit registered;
Pro keeps `wppilot/backup-trigger` and its fresh-backup gate. The output is kept identical to
Pro's, which still registers both names first on a site running Pro 1.10.0.

## Tests

`tests/Unit/Kits/BackupStatus/` in the WPPilot repository (not shipped).
<!-- /kit-export:omit -->
